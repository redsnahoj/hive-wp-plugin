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
  add_menu_page(
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

  // --- Start Save changes / Validation ---

  $success_message = '';
  $error_message = '';

  if(isset($_POST['hive_connect_nonce']) && wp_verify_nonce($_POST['hive_connect_nonce'], 'hive_connect_save_settings')) {
    
    // 1. Sanitize and trim inputs
    $input_username = sanitize_text_field($_POST['hive_username']);
    $input_api_url = esc_url_raw($_POST['hive_api_url']);
    
    // 2. Validation for Hive Username (Required field)
    if (empty($input_username)) {
      $error_message = 'Error: The Hive Username field cannot be empty.';
    } else {
      
      // 3. Set Default API URL if input is empty
      if (empty($input_api_url)) {
        $final_api_url = 'https://api.hive.blog/';
      } else {
        $final_api_url = $input_api_url;
      }
      
      // 4. Save validated data
      update_option('hive_connect_username', $input_username);
      update_option('hive_connect_api_url', $final_api_url);
      
      $success_message = 'Settings saved.';
    }
  }

  // Display messages
  if (!empty($error_message)) {
    echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
  }
  if (!empty($success_message)) {
    echo '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
  }

  // --- End Save changes / Validation ---

  $current_username = get_option( 'hive_connect_username', '' );
  $current_api_url = get_option( 'hive_connect_api_url', 'https://api.hive.blog/' ); 
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
          <th scope="row"><label for="hive_api_url">Hive API URL</label></th>
          <td>
            <input name="hive_api_url" type="url" id="hive_api_url" value="<?php echo esc_attr($current_api_url); ?>" class="regular-text" placeholder="https://api.hive.blog/">
            <p class="description">Enter the URL of the Hive API node you want to use. Leave it empty to use the default: <code>https://api.hive.blog/</code>.</p>
            <p class="description">You can find a list of public API nodes here: <a href="https://developers.hive.io/quickstart/#quickstart-hive-full-nodes" target="_blank">Hive Public API Nodes</a>.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Instructions for use</th>
          <td>
            <p><strong>Step 1:</strong> Set your Hive Username and preferred Hive API URL above. (Default API: <code>https://api.hive.blog/</code>).</p>
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
    $url = get_option( 'hive_connect_api_url', 'https://api.hive.blog/' );
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
    'viewer_page' => 'view-post-hive', 
  ], $atts);

  $username = get_option('hive_connect_username');

  if(empty($username)) {
    return '<p class="hive-error">Please configure your Hive username in the plugin settings.</p>';
  }

  $posts = hive_viewer_api_call('get_discussions_by_blog', [
    [
      'tag'    => $username,
      'limit'  => (int) $atts['limit'],
    ]
  ]);

  if(!is_array($posts) || empty($posts)) {
    return '<p class="hive-no-posts">The posts could not be loaded, or the user has no posts.</p>';
  }

ob_start();
?>

  <section class="hive-connect-posts-list">
    <h2 class="hive-list-title">Latest posts by @<?php echo esc_html($username); ?></h2>
    
    <?php foreach($posts as $post) : 
      $link = get_site_url(null, $atts['viewer_page']) . '?permlink=' . urlencode($post['permlink']) . '&author=' . urlencode($post['author']);
      $clean_content = wp_strip_all_tags($post['body']);
      $excerpt = wp_trim_words($clean_content, 30, '...');

      $thumbnail_url = '';
      if (!empty($post['json_metadata'])) {
        $metadata = json_decode($post['json_metadata'], true);

        if (!empty($metadata['image']) && is_array($metadata['image'])) {
          $thumbnail_url = $metadata['image'][0];
        }
      }
    ?>

    <article class="hive-connect-post-item has-post-thumbnail">
      <?php if ($thumbnail_url) : ?>
      <div class="post-thumbnail">
        <a href="<?php echo esc_url($link); ?>">
          <img src="<?php echo esc_url($thumbnail_url); ?>" alt="<?php echo esc_attr($post['title']); ?>" loading="lazy">
        </a>
      </div>
      <?php endif; ?>

      <div class="post-content-wrapper">
        <header class="hive-post-header">
          <h3 class="entry-title">
            <a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $post['title'] ); ?></a>
          </h3>
        </header>

        <div class="entry-summary">
          <p><?php echo esc_html($excerpt); ?></p>
        </div>

        <footer class="entry-footer">
          <span class="posted-on"><?php echo esc_html( date( 'd/m/Y', strtotime( $post['created'] ) ) ); ?></span>
          <span class="post-votes"> | Votes: <?php echo (int) $post['net_rshares']; ?></span>
          <a href="<?php echo esc_url( $link ); ?>" class="read-more">Read more &rarr;</a>
        </footer>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
<?php

return ob_get_clean();
}

add_shortcode( 'hive_posts_list', 'hive_connect_posts_list_shortcode' );

// 4. SHORTCODE TO VIEW THE FULL POST

function hive_viewer_post_viewer_shortcode()
{
  $permlink = isset($_GET['permlink']) ? sanitize_text_field($_GET['permlink']) : '';
  $author = isset($_GET['author']) ? sanitize_text_field( $_GET['author']) : '';

  if(empty($permlink) || empty($author)) {
    return '<p>Please select an item from the list of publications.</p>';
  }

  $post = hive_viewer_api_call('get_content', [$author, $permlink]);

  if(!is_array($post) || empty($post['body'])) {
    return '<p>Error loading article content.</p>';
  }

  $body_content = wpautop( html_entity_decode( $post['body'] ) );

ob_start();

?>
  <article class="hive-connect-full-post">
    <header class="entry-header">
      <h1 class="entry-title"><?php echo esc_html($post['title']); ?></h1>
      <div class="entry-meta">
        <span class="byline">By: <strong>@<?php echo esc_html( $post['author'] ); ?></strong></span>
        <span class="posted-on"> | Date: <?php echo esc_html(date('d/m/Y H:i', strtotime($post['created']))); ?></span>
      </div>
    </header>

    <div class="entry-content">
      <?php echo $body_content; ?>
    </div>

    <footer class="entry-footer">
      <div class="hive-stats">
        <p>Value: $<?php echo number_format((float) $post['pending_payout_value'], 2); ?> | Comments: <?php echo (int) $post['children']; ?></p>
      </div>
      <a href="https://peakd.com/@<?php echo esc_attr($post['author']); ?>/<?php echo esc_attr($post['permlink']); ?>" target="_blank" class="hive-explorer-link">View in Hive Explorer &rarr;</a>
        
      <div class="post-navigation">
        <a href="javascript:history.back()" class="button">&larr; Back</a>
      </div>
    </footer>
  </article>

<?php

return ob_get_clean();
}

add_shortcode('hive_post_viewer', 'hive_viewer_post_viewer_shortcode');

// 5. MINIMAL CSS FOR STRUCTURE
function hive_connect_basic_styles()
{
?>
  <style>
    .hive-connect-posts-list { margin-bottom: 2em; }
    .hive-connect-post-item { 
      display: flex;
      flex-direction: column;
      gap: 20px;
      margin-bottom: 3em; 
      padding-bottom: 2em; 
      border-bottom: 1px solid #eee; 
    }

    @media (min-width: 600px) {
      .hive-connect-post-item { flex-direction: row; align-items: flex-start; }
      .post-thumbnail { flex: 0 0 200px; }
      .post-content-wrapper { flex: 1; }
    }

    .post-thumbnail img { 
      width: 100%; 
      height: auto; 
      border-radius: 4px; 
      display: block;
      object-fit: cover;
      aspect-ratio: 16 / 9;
    }

    .hive-connect-post-item h3 { margin-top: 0; margin-bottom: 0.5em; }
    .entry-meta, .entry-footer { font-size: 0.85em; color: #666; }
    .entry-content { line-height: 1.6; margin-top: 1.5em; }
    
    .read-more { font-weight: bold; text-decoration: none; margin-left: 10px; }
    .hive-explorer-link { display: block; margin: 1em 0; font-weight: bold; }
    
    .post-navigation .button {
      display: inline-block;
      padding: 8px 16px;
      background: #f7f7f7;
      border: 1px solid #ccc;
      color: #333;
      text-decoration: none;
      border-radius: 3px;
    }
  </style>
<?php
}

add_action('wp_head', 'hive_connect_basic_styles');