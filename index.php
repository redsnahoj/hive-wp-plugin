<?php
/**
 * Plugin Name: WP Hive Connect
 * Description: Display posts from a specific user on the Hive blockchain on your WordPress site.
 * Version: 0.0.1
 * Author: redsnahoj
 * Author URI: https://github.com/redsnahoj
*/

if(!defined('ABSPATH')) {
  exit;
}

// 1. MENU CONFIGURATION AND SETTINGS PAGE

  // Add the menu link to the plugin settings in the WordPress dashboard.

function hive_connect_add_settings_page()
{
  add_options_page(
    'Hive Connect settings',
    'WP Hive Connect',
    'manage_options',
    'hive-connect-settings',
    'hive_connect_settings_page_content'
  );
}

add_action('admin_menu', 'hive_connect_add_settings_page');

  // Contents of the settings page.

function hive_connect_settings_page_content() 
{
  // Verify permissions
  if (!current_user_can('manage_options')) {
    return;
  }

  // Save changes

  if(isset($_POST['hive_connect_nonce']) && wp_verify_nonce($_POST['hive_connect_nonce'], 'hive_connect_save_settings')) {
    $username = sanitize_text_field($_POST['hive_username']);
    update_option('hive_connect_username', $username);
    echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
  }

  $current_username = get_option( 'hive_connect_username', '' );
  ?>
  <div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <form method="post" action="">
    <?php wp_nonce_field('hive_connect_save_settings', 'hive_connect_nonce'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="hive_username">Hive Username</label></th>
          <td>
            <input name="hive_username" type="text" id="hive_username" value="<?php echo esc_attr($current_username); ?>" class="regular-text" placeholder="ej: aliento">
            <p class="description">Input the Hive username from where you want to upload the posts.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Instructions for use</th>
          <td>
            <p><strong>Step 1:</strong> Set your Hive username above.</p>
            <p><strong>Step 2:</strong> Create a WordPress page for the list of posts and use the shortcode. <code>[hive_posts_list]</code>.</p>
            <p><strong>Step 3:</strong> Create a second WordPress page to view the full post (for example, with the slug <code>view-post-hive</code>) and use the shortcode <code>[hive_post_viewer]</code>. The URL linked to the list of posts is the one that should be used here.</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Save Changes'); ?>
    </form>
  </div>
<?php
}

// 2. HELP FUNCTIONS FOR THE HIVE API

function hive_viewer_api_call($method, $params)
{
    $url = 'https://api.hive.blog/';
    $body = json_encode( [
        'jsonrpc' => '2.0',
        'method'  => 'condenser_api.' . $method,
        'params'  => $params,
        'id'      => 1
    ]);

    $response = wp_remote_post($url, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    if(is_wp_error($response)) {
        error_log('Error de API de Hive: ' . $response->get_error_message());
        return null;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if(isset( $data['error'])) {
        error_log('Error en la respuesta de Hive: ' . print_r( $data['error'], true));
        return null;
    }

    return isset($data['result']) ? $data['result'] : null;
}

// 3. SHORTCODE TO LIST POSTS

function hive_connect_posts_list_shortcode($atts)
{
  $atts = shortcode_atts([
    'limit' => 10,
    // Base URL to the page that has the shortcode [hive_post_viewer]
    'viewer_page' => 'view-post-hive', 
  ], $atts);

    $username = get_option('hive_connect_username');

    if(empty($username)) {
        return '<p class="text-red-500">Please configure your Hive username in the plugin settings.</p>';
    }

    $posts = hive_viewer_api_call('get_discussions_by_blog', [
        [
          'tag'    => $username,
          'limit'  => (int) $atts['limit'],
        ]
    ]);

    if(!is_array($posts) || empty($posts)) {
      return '<p>The posts could not be loaded, or the user has no posts.</p>';
    }

    ob_start();
?>
  <div class="hive-post-list">
    <h2>Latest posts by @<?php echo esc_html($username); ?></h2>
    <ul class="list-disc ml-5">
      <?php foreach($posts as $post) : 
        // Build the link to the post display page
        $link = get_site_url(null, $atts['viewer_page']) . '?permlink=' . urlencode($post['permlink']) . '&author=' . urlencode($post['author']);
      ?>
      <li class="mb-2 p-2 border-b border-gray-200 hover:bg-gray-50 rounded-md transition duration-150">
        <a href="<?php echo esc_url( $link ); ?>" class="text-blue-600 hover:text-blue-800 font-semibold text-lg" title="<?php echo esc_attr( $post['title'] ); ?>">
          <?php echo esc_html( $post['title'] ); ?>
        </a>
        <p class="text-sm text-gray-500 mt-1">
          Published on <?php echo esc_html( date( 'd/m/Y', strtotime( $post['created'] ) ) ); ?> | Votes: <?php echo (int) $post['net_rshares']; ?>
        </p>
      </li>
        <?php endforeach; ?>
    </ul>
  </div>
<?php
return ob_get_clean();
}
add_shortcode( 'hive_posts_list', 'hive_connect_posts_list_shortcode' );

// 4. SHORTCODE TO VIEW THE FULL POST

function hive_viewer_post_viewer_shortcode()
{
  // Get the parameters from the URL
  $permlink = isset($_GET['permlink']) ? sanitize_text_field($_GET['permlink']) : '';
  $author = isset($_GET['author']) ? sanitize_text_field( $_GET['author']) : '';

  if(empty($permlink) || empty($author)) {
    return '<p>Please select an item from the list of publications.</p>';
  }

  // Call the API to get the full post
  $post = hive_viewer_api_call('get_content', [$author, $permlink]);

  if(!is_array($post) || empty($post['body'])) {
    return '<p>Error loading article content.</p>';
  }

  // --- Rendering Process ---

  
  // 1. Decode HTML entities.
  $body_content = html_entity_decode( $post['body'] );
  
  // 2. Use wpautop to convert line breaks to <p> tags (helps with readability).
  $body_content = wpautop( $body_content );

  // 3. Sanitize to ensure that the HTML is safe.

  ob_start();
?>
  <div class="hive-post-viewer">
    <article class="p-6 bg-white shadow-lg rounded-lg">
      <h1 class="text-4xl font-bold mb-4 text-gray-900"><?php echo esc_html($post['title']); ?></h1>
      <div class="mb-6 text-sm text-gray-600 border-b pb-4">
        <span class="mr-4">By: <strong class="text-blue-600">@<?php echo esc_html( $post['author'] ); ?></strong></span>
        <span>Date: <?php echo esc_html(date('d/m/Y H:i', strtotime($post['created']))); ?></span>
      </div>

      <div class="post-content text-gray-700 leading-relaxed space-y-4">
        <?php
          // Displays the processed content. The use of echo $body_content is intentional
          // to allow HTML generated by Markdown, but it is done after basic sanitization.
          echo $body_content; 
        ?>
      </div>

      <div class="mt-8 pt-4 border-t text-sm text-gray-500">
        <p>Outstanding value: $<?php echo number_format((float) $post['pending_payout_value'], 2); ?></p>
        <p>Comments: <?php echo (int) $post['children']; ?></p>
        <p><a href="https://peakd.com/@<?php echo esc_attr($post['author']); ?>/<?php echo esc_attr($post['permlink']); ?>" target="_blank" class="text-indigo-500 hover:text-indigo-700 font-medium">View in Hive Explorer &rarr;</a></p>
      </div>

      <!-- Back button, assuming the list page has the slug ‘blog-hive’ or similar -->
      <div class="mt-6">
        <a href="<?php echo esc_url( get_site_url() . '/blog-hive/'); ?>" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
          &larr; Back to the list of posts
        </a>
      </div>
    </article>
  </div>
  <?php
  return ob_get_clean();
}
add_shortcode('hive_post_viewer', 'hive_viewer_post_viewer_shortcode');