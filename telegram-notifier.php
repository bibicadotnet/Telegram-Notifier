<?php

/**
 * Plugin Name: Telegram Notifier
 * Description: Gửi thông báo lên Telegram khi có bài viết hoặc trang mới được tạo hoặc cập nhật.
 * Version: 1.2
 * Author: bibica
 * Author URI: https://bibica.net
 * Plugin URI: https://bibica.net/telegram-notifier
 * Text Domain: telegram-notifier
 * License: GPL-3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// Ngăn chặn truy cập trực tiếp
if (!defined('ABSPATH')) {
    exit;
}

// Thêm tùy chọn vào metabox "Publish"
function telegram_notifier_add_publish_checkbox() {
    global $post;
    $value = get_post_meta($post->ID, '_telegram_notifier_send', true);
    $checked = checked($value, '1', false);
    echo '<div class="misc-pub-section misc-pub-telegram-notifier">';
    echo '<label><input type="checkbox" name="telegram_notifier_send" value="1" ' . $checked . '/> ';
    _e('Telegram Notifier', 'telegram-notifier');
    echo '</label>';
    echo '</div>';
}
// Sử dụng mức độ ưu tiên cao hơn (số lớn hơn) để đảm bảo rằng nó xuất hiện cuối cùng
add_action('post_submitbox_misc_actions', 'telegram_notifier_add_publish_checkbox', 11);

// Lưu dữ liệu khi bài viết được lưu
function telegram_notifier_save_meta_box_data($post_id) {
    // Kiểm tra tự động lưu và bản sửa đổi
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($post_id)) {
        return;
    }

    // Kiểm tra quyền người dùng
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $should_send = isset($_POST['telegram_notifier_send']) ? '1' : '';
    if ($should_send === '1') {
        update_post_meta($post_id, '_telegram_notifier_send', '1');
    } else {
        delete_post_meta($post_id, '_telegram_notifier_send');
    }
}
add_action('save_post', 'telegram_notifier_save_meta_box_data');

// Gửi thông báo đến Telegram
function telegram_notifier_send_telegram_message($post_id) {
    // Chỉ gửi thông báo nếu checkbox được đánh dấu và bài viết đã được công bố
    if (get_post_meta($post_id, '_telegram_notifier_send', true) !== '1' || get_post_status($post_id) !== 'publish') {
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        return;
    }

    $telegram_token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
    $telegram_chat_id = defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '';

    if (empty($telegram_token) || empty($telegram_chat_id)) {
        return;
    }

    $featured_image_url = '';
    if (has_post_thumbnail($post_id)) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $featured_image_url = wp_get_attachment_url($thumbnail_id);
            $headers = wp_remote_head($featured_image_url);
            $size = wp_remote_retrieve_header($headers, 'content-length');
            
            if ($size > 10 * 1024 * 1024) {
                $featured_image_url = '';
            } elseif ($size > 5 * 1024 * 1024) {
                $featured_image_url = get_attached_file($thumbnail_id);
            }
        }
    }

    $post_title = htmlspecialchars($post->post_title);
    $post_excerpt = get_the_excerpt($post_id);
    if (empty($post_excerpt)) {
        $post_content = apply_filters('the_content', $post->post_content);
        $post_excerpt = strip_tags($post_content);
        $post_excerpt = wp_trim_words($post_excerpt, 50, '...');
    } else {
        $post_excerpt = wp_trim_words($post_excerpt, 50, '...');
    }

    // Đảm bảo đoạn trích không vượt quá 1024 ký tự cho caption hoặc 4096 cho text
    $max_caption_length = $featured_image_url ? 1024 : 4096;
    $post_excerpt = telegram_notifier_trim_by_characters(htmlspecialchars($post_excerpt), $max_caption_length - mb_strlen($post_title, 'UTF-8') - 50); // Trừ đi khoảng không cho tiêu đề và URL

    $full_url = get_permalink($post_id);
    $caption = sprintf(
        "<b>%s</b>\n\n%s\n\nĐọc thêm: %s",
        $post_title,
        $post_excerpt,
        $full_url
    );

    if ($featured_image_url) {
        $url = "https://api.telegram.org/bot$telegram_token/sendPhoto";
        $data = [
            'chat_id' => $telegram_chat_id,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];
        
        if (filter_var($featured_image_url, FILTER_VALIDATE_URL)) {
            $data['photo'] = $featured_image_url;
            wp_remote_post($url, ['body' => $data, 'timeout' => 10, 'blocking' => false]);
        } else {
            $data['photo'] = curl_file_create($featured_image_url);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_exec($ch);
            curl_close($ch);
        }
    } else {
        $url = "https://api.telegram.org/bot$telegram_token/sendMessage";
        wp_remote_post($url, [
            'body' => [
                'chat_id' => $telegram_chat_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ],
            'timeout' => 10,
            'blocking' => false,
        ]);
    }

    // Xóa metadata để đảm bảo checkbox được đặt lại
    delete_post_meta($post_id, '_telegram_notifier_send');
}
add_action('save_post', 'telegram_notifier_send_telegram_message', 999, 1);

/**
 * Cắt chuỗi theo số lượng ký tự và thêm dấu ba chấm nếu bị cắt.
 */
function telegram_notifier_trim_by_characters($text, $length, $ellipsis = '...') {
    if (mb_strlen($text, 'UTF-8') > $length) {
        $length = max(0, $length - mb_strlen($ellipsis, 'UTF-8'));
        return mb_substr($text, 0, $length, 'UTF-8') . $ellipsis;
    }
    return $text;
}
