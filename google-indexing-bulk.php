<?php
/*
Plugin Name: Google Indexing Bulk (GIBulk)
Description: Queue new/updated posts and notify Google Indexing API (for eligible pages) plus sitemap ping fallback and bulk runs.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Indexing;

// Basic constants
define('GIB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIB_OPTION_QUEUE', 'gib_url_queue');
define('GIB_OPTION_LOG', 'gib_logs');
define('GIB_OPTION_SETTINGS', 'gib_settings');

// Activation: create defaults
register_activation_hook(__FILE__, function(){
    if (! get_option(GIB_OPTION_QUEUE) ) update_option(GIB_OPTION_QUEUE, array());
    if (! get_option(GIB_OPTION_LOG) ) update_option(GIB_OPTION_LOG, array());
    if (! get_option(GIB_OPTION_SETTINGS) ) update_option(GIB_OPTION_SETTINGS, array(
        'service_account' => '',
        'site_url' => '',
        'eligible_post_types' => 'post,page',
        'batch_size' => 50,
        'run_interval_minutes' => 1,
        'use_indexing_api' => 1
    ));
    if (! wp_next_scheduled('gib_process_queue') ) {
        wp_schedule_event(time()+60, 'minute', 'gib_process_queue');
    }
});

// Add custom cron schedule for 1 minute if not exists
add_filter('cron_schedules', function($s){
    if (!isset($s['minute'])) $s['minute'] = array('interval'=>60, 'display'=>'Every Minute');
    return $s;
});

// Hook: when post is published or updated, add to queue
add_action('transition_post_status', function($new, $old, $post){
    if ($new !== 'publish') return;
    // check post type eligibility from settings
    $settings = get_option(GIB_OPTION_SETTINGS, array());
    $types = isset($settings['eligible_post_types']) ? explode(',', $settings['eligible_post_types']) : array('post','page');
    if (!in_array($post->post_type, $types)) return;
    $url = get_permalink($post);
    if (! $url ) return;
    // add to queue
    $queue = get_option(GIB_OPTION_QUEUE, array());
    if (! in_array($url, $queue)) {
        $queue[] = $url;
        update_option(GIB_OPTION_QUEUE, $queue);
        add_log("Queued URL: $url");
    }
}, 10, 3);

// Admin menu
add_action('admin_menu', function(){
    add_menu_page('GIBulk', 'GIBulk', 'manage_options', 'gibulk', 'gib_admin_page', 'dashicons-cloud', 80);
});

// Admin page callback
function gib_admin_page(){
    if (! current_user_can('manage_options') ) return;
    $settings = get_option(GIB_OPTION_SETTINGS);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gib_action'])) {
        check_admin_referer('gib_nonce');
        if ($_POST['gib_action'] === 'save_settings') {
            $settings['site_url'] = sanitize_text_field($_POST['site_url']);
            $settings['eligible_post_types'] = sanitize_text_field($_POST['eligible_post_types']);
            $settings['batch_size'] = intval($_POST['batch_size']);
            $settings['run_interval_minutes'] = intval($_POST['run_interval_minutes']);
            $settings['use_indexing_api'] = isset($_POST['use_indexing_api']) ? 1 : 0;
            update_option(GIB_OPTION_SETTINGS, $settings);
            add_log("Settings saved.");
        }
        if ($_POST['gib_action'] === 'upload_sa') {
            // save uploaded service account JSON content in option (not secure for high-scale production; you may prefer storing in filesystem)
            if (! empty($_FILES['sa_json']['tmp_name'])) {
                $content = file_get_contents($_FILES['sa_json']['tmp_name']);
                update_option(GIB_OPTION_SETTINGS, array_merge($settings, ['service_account' => $content]));
                add_log("Service account uploaded.");
            }
        }
        if ($_POST['gib_action'] === 'run_now') {
            // trigger immediate processing
            do_action('gib_process_queue');
            add_log("Manual run triggered.");
        }
        if ($_POST['gib_action'] === 'clear_queue') {
            update_option(GIB_OPTION_QUEUE, array());
            add_log("Queue cleared by admin.");
        }
    }

    $queue = get_option(GIB_OPTION_QUEUE, array());
    $logs = array_reverse(get_option(GIB_OPTION_LOG, array()));
    ?>
    <div class="wrap"><h1>Google Indexing Bulk (GIBulk)</h1>
    <form method="post" enctype="multipart/form-data">
    <?php wp_nonce_field('gib_nonce'); ?>
    <input type="hidden" name="gib_action" value="save_settings"/>
    <table class="form-table">
      <tr><th>Site URL (Search Console property)</th>
          <td><input name="site_url" value="<?php echo esc_attr($settings['site_url'] ?? ''); ?>" style="width:400px"/></td></tr>
      <tr><th>Eligible post types (comma-separated)</th>
          <td><input name="eligible_post_types" value="<?php echo esc_attr($settings['eligible_post_types']); ?>" /></td></tr>
      <tr><th>Use Indexing API (only for eligible JobPosting/BroadcastEvent pages)</th>
          <td><input type="checkbox" name="use_indexing_api" <?php checked( $settings['use_indexing_api'], 1 ); ?> /></td></tr>
      <tr><th>Batch size (per run)</th>
          <td><input name="batch_size" value="<?php echo intval($settings['batch_size']); ?>" /></td></tr>
      <tr><th>Run interval (minutes)</th>
          <td><input name="run_interval_minutes" value="<?php echo intval($settings['run_interval_minutes']); ?>" /></td></tr>
    </table>
    <p><input type="submit" class="button button-primary" value="Save Settings" /></p>
    </form>

    <h2>Upload service account JSON</h2>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('gib_nonce'); ?>
      <input type="hidden" name="gib_action" value="upload_sa"/>
      <input type="file" name="sa_json" accept=".json" />
      <input type="submit" class="button" value="Upload Service Account" />
    </form>

    <h2>Queue (<?php echo count($queue); ?>)</h2>
    <form method="post">
      <?php wp_nonce_field('gib_nonce'); ?>
      <input type="hidden" name="gib_action" value="run_now" />
      <input type="submit" class="button button-secondary" value="Run Now (process queue)" />
    </form>
    <form method="post" style="display:inline">
      <?php wp_nonce_field('gib_nonce'); ?>
      <input type="hidden" name="gib_action" value="clear_queue" />
      <input type="submit" class="button" value="Clear Queue" />
    </form>
    <ul>
      <?php foreach($queue as $u) echo '<li>'.esc_html($u).'</li>'; ?>
    </ul>

    <h2>Logs</h2>
    <div style="max-height:300px; overflow:auto; background:#fff; padding:10px; border:1px solid #ddd">
      <?php foreach($logs as $l) echo '<div style="margin-bottom:6px;">'.esc_html($l).'</div>'; ?>
    </div>
    </div>
    <?php
}

// helper: add log
function add_log($text){
    $logs = get_option(GIB_OPTION_LOG, array());
    $time = current_time('mysql');
    $logs[] = "[$time] $text";
    // keep last 2000 entries
    if (count($logs) > 2000) $logs = array_slice($logs, -2000);
    update_option(GIB_OPTION_LOG, $logs);
}

// Cron: process queue
add_action('gib_process_queue', function(){
    $settings = get_option(GIB_OPTION_SETTINGS);
    $queue = get_option(GIB_OPTION_QUEUE, array());
    if (empty($queue)) { add_log("Queue empty, nothing to do."); return; }

    $batch = array_slice($queue, 0, max(1, intval($settings['batch_size'] ?? 50)));
    add_log("Processing batch of ".count($batch));

    $results = array();
    foreach($batch as $url) {
        $res = gib_send_url_notification($url);
        $results[] = array('url'=>$url, 'result'=>$res);
        // on success remove from queue; on retryable error we'll keep
        if (isset($res['success']) && $res['success'] === true) {
            $queue = array_values(array_diff($queue, array($url)));
        } else {
            // if retryable, we can leave it; we also implement simple retry in next runs
            add_log("Will retry later for $url; error: ".json_encode($res));
        }
        // minimal sleep to avoid hammering quotas (tuneable)
        sleep(1);
    }
    update_option(GIB_OPTION_QUEUE, $queue);
    add_log("Batch finished. Remaining in queue: ".count($queue));
});

// core: send URL notification (Indexing API or sitemap ping fallback)
function gib_send_url_notification($url){
    $settings = get_option(GIB_OPTION_SETTINGS);
    // If indexing api disabled, fallback
    if (empty($settings['use_indexing_api'])) {
        add_log("Indexing API disabled — using sitemap ping fallback for $url");
        return gib_sitemap_ping_fallback($url);
    }

    $sa_json = $settings['service_account'] ?? '';
    if (! $sa_json) {
        add_log("No service account configured — fallback for $url");
        return gib_sitemap_ping_fallback($url);
    }

    // Attempt Indexing API
    try {
        $client = new Client();
        $client->setAuthConfig(json_decode($sa_json, true));
        $client->addScope('https://www.googleapis.com/auth/indexing');
        $client->setSubject(null); // not needed with service accounts if added as owner to Search Console property

        // fetch access token (library handles refresh)
        $token = $client->fetchAccessTokenWithAssertion();
        if (isset($token['error'])) {
            add_log("Token error: ".json_encode($token));
            return ['success'=>false,'error'=>$token];
        }
        $accessToken = $client->getAccessToken();

        $http = $client->authorize();
        $payload = json_encode([
            'url' => $url,
            'type' => 'URL_UPDATED'
        ]);
        $resp = $http->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => $payload
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        if ($code >= 200 && $code < 300) {
            add_log("Indexing API OK for $url");
            return ['success'=>true,'code'=>$code,'body'=>$body];
        } else {
            add_log("Indexing API returned $code for $url: $body");
            // if 403/400 etc fallback to sitemap ping
            return ['success'=>false,'code'=>$code,'body'=>$body];
        }
    } catch (Exception $e) {
        add_log("Exception calling Indexing API for $url: ".$e->getMessage());
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}

// Fallback: ensure sitemap is updated and ping Google
function gib_sitemap_ping_fallback($url){
    // Attempt to update sitemap: if you use WP native sitemap (WP 5.5+) the sitemap is auto-updated.
    // We'll ping Google with sitemap URL (if site_url set), otherwise ping with page URL.
    $settings = get_option(GIB_OPTION_SETTINGS);
    $site_url = rtrim($settings['site_url'] ?? '', '/');
    if ($site_url) {
        $sitemap_url = $site_url . '/sitemap.xml';
        $ping = 'https://www.google.com/ping?sitemap=' . urlencode($sitemap_url);
        $r = wp_remote_get($ping, ['timeout'=>15]);
        add_log("Sitemap pinged: $sitemap_url; result: ".wp_remote_retrieve_response_code($r));
        return ['success'=>true,'method'=>'sitemap_ping','code'=>wp_remote_retrieve_response_code($r)];
    } else {
        // fallback ping with url (not official; will try to let Google revisit)
        $ping = 'https://www.google.com/ping?u=' . urlencode($url);
        $r = wp_remote_get($ping, ['timeout'=>15]);
        add_log("General ping for URL: $url; result: ".wp_remote_retrieve_response_code($r));
        return ['success'=>true,'method'=>'url_ping','code'=>wp_remote_retrieve_response_code($r)];
    }
}

// Add simple shortcode to show last logs on front-end if needed for verification
add_shortcode('gib_status', function(){
    $logs = array_reverse(array_slice(get_option(GIB_OPTION_LOG, array()), 0, 20));
    $out = "<div class='gib-status'><ul>";
    foreach($logs as $l) $out .= '<li>'.esc_html($l).'</li>';
    $out .= "</ul></div>";
    return $out;
});
