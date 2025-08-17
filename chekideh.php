<?php
/*
 * Plugin Name:       Chekideh
 * Description:       Generates a summary of comments for Posts and WooCommerce products using AI.
 * Plugin URI:        https://cache.cool/chekideh
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mahyar Rezghi
 * Author URI:        https://cache.cool
 * Text Domain:       chekideh
 * Domain Path:       /languages
 */

// Prevent direct access
if (! defined('WP_DEBUG')) {
	die( 'Direct access forbidden.' );
}

// Composer
require __DIR__ . '/vendor/autoload.php'; // remove this line if you use a PHP Framework.
require_once( plugin_dir_path( __FILE__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php' );

use Orhanerday\OpenAi\OpenAi;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Start CMB2 functions
if ( file_exists( dirname( __FILE__ ) . '/includes/CMB2/init.php' ) ) {
    require_once dirname( __FILE__ ) . '/includes/CMB2/init.php';
}
if ( file_exists( dirname( __FILE__ ) . '/includes/cmb2-extension/init.php' ) ) {
    require_once dirname( __FILE__ ) . '/includes/cmb2-extension/init.php';
}
if ( file_exists( dirname( __FILE__ ) . '/includes/cmb2-field-post-search-ajax/cmb-field-post-search-ajax.php' ) ) {
    require_once dirname( __FILE__ ) . '/includes/cmb2-field-post-search-ajax/cmb-field-post-search-ajax.php';
}

// Setup plugin update checker
add_action('init', function() {
    $myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://static.cache.cool/wp-plugins/chekideh/plugin.json', // Metadata file URL
        __FILE__,                                                // Main plugin file
        'chekideh'                                                // Plugin slug
    );
});

// Load plugin text domain
add_action( 'init', 'chekideh_load_textdomain' );
function chekideh_load_textdomain() {
  load_plugin_textdomain( 'chekideh', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

class ChekidehStructuredResult {
    public $success;
    public $data;
    public $error;

    public function __construct($success, $data = null, $error = null) {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
    }
}

// Hook in and register a metabox to handle a plugin options page and adds a menu item.
function chekideh_register_plugin_options_metabox() {
	/**
	 * Registers options page menu item and form.
	 */

    // General plugin options
	$cmb_general_options = new_cmb2_box( array(
		'id'           => 'chekideh_plugin_options_tab',
		'title'        => esc_html__( 'Chekideh Settings', 'chekideh' ),
		'object_types' => array( 'options-page' ),

		/*
		 * The following parameters are specific to the options-page box
		 * Several of these parameters are passed along to add_menu_page()/add_submenu_page().
		 */

		'option_key'      => 'chekideh_plugin_options', // The option key and admin menu page slug.
		'icon_url'        => get_site_url() . '/wp-content/plugins/chekideh/assets/images/menu_icon.png', // Menu icon. Only applicable if 'parent_slug' is left empty.
		'menu_title'              => esc_html__( 'Chekideh', 'chekideh' ), // Falls back to 'title' (above).
		'position'                => 2, // Menu position. Only applicable if 'parent_slug' is left empty.
		'save_button'             => esc_html__( 'Save Changes', 'chekideh' ), // The text for the options-page save button. Defaults to 'Save'.
		'tab_group'               => 'plugin_options', // Tab-group identifier, enables options page tab navigation.
		'tab_title'               => esc_html__( 'General', 'chekideh' ), // Falls back to 'title' (above).
	) );

	/**
	 * Options fields ids only need
	 * to be unique within this box.
	 * Prefix is not needed.
	 */
	$cmb_general_options->add_field( array(
		'name'    => esc_html__( 'Creativity', 'chekideh' ),
		'desc'    => esc_html__( 'What level of creativity versus factual accuracy do you prefer for the posts?', 'chekideh' ),
		'id'      => 'creativity',
		'type'    => 'select',
		'default'          => '0.5',
		'options'          => array(
			'0.1' => __( 'Low', 'chekideh' ),
			'0.5'   => __( 'Medium', 'chekideh' ),
			'1.0'     => __( 'High', 'chekideh' ),
		),
	) );

    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'Minimum Comments Count', 'chekideh' ),
        'desc'    => esc_html__( 'Minimum comments needed for comments summary generation.', 'chekideh' ),
        'id'      => 'minimum_comments_count',
        'type'    => 'text_small',
        'default'    => '10',
    ) );

    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'Cache Duration', 'chekideh' ),
        'desc'    => esc_html__( 'Cache duration for generated comments summary. (in Days)', 'chekideh' ),
        'id'      => 'cache_duration',
        'type'    => 'text_small',
        'default'    => '7',
    ) );

    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'API Base URL', 'chekideh' ),
        'desc'    => esc_html__( 'Please enter a URL for an OpenAI-compatible API. For example: https://api.openai.com/', 'chekideh' ),
        'id'      => 'openai_base_url',
        'type'    => 'text_url',
    ) );
    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'API Key', 'chekideh' ),
        'desc'    => esc_html__( 'Please enter a valid API key.', 'chekideh' ),
        'id'      => 'openai_api_key',
        'type'    => 'text',
    ) );
    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'Model Name', 'chekideh' ),
        'desc'    => esc_html__( 'Please enter an AI model to use. It must be a chat model.', 'chekideh' ),
        'id'      => 'openai_model_name',
        'type'    => 'text',
    ) );
    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'Max Tokens', 'chekideh' ),
        'id'      => 'openai_max_tokens',
        'default' => '4000',
        'type'    => 'text',
    ) );
    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'System Prompt', 'chekideh' ),
        'desc'    => esc_html__( 'Please provide a system prompt for the model.', 'chekideh' ),
        'id'      => 'openai_system_prompt',
        'default' => 'You are a helpful ai assistant.',
        'type'    => 'textarea',
    ) );
    $cmb_general_options->add_field( array(
        'name'    => esc_html__( 'Summary Prompt', 'chekideh' ),
        'desc'    => esc_html__( 'Please provide a prompt for the generation of the comments summary.', 'chekideh' ),
        'id'      => 'openai_comments_summary_prompt',
        'default' => 'Please review the user comments provided below and create a clear, well-structured summary. The summary should:

        Highlight the main positive points mentioned by users.
        Highlight the main negative points mentioned by users.
        Identify any recurring patterns or important themes that appear across multiple comments.
        The goal is to give a concise and useful overview of users\' experiences.

        The output must be in HTML format using only the following tags: <p>, <ul>, and <li>.

        Do not include triple backticks or any additional formatting outside of the HTML.

        Write in a neutral and professional tone that is easy to understand.',
        'type'    => 'textarea',
    ) );

    // Style Page Options
    $cmb_style_options = new_cmb2_box( array(
		'id'           => 'chekideh_style_options_tab',
        'title'        => esc_html__( 'Chekideh Settings', 'chekideh' ),
        'object_types' => array('options-page'),
		'option_key'      => 'chekideh_style_options', // The option key and admin menu page slug.
        'parent_slug'             => 'chekideh_plugin_options',
		'menu_title'              => esc_html__( 'Style', 'chekideh' ), // Falls back to 'title' (above).
		'position'                => 2, // Menu position. Only applicable if 'parent_slug' is left empty.
		'save_button'             => esc_html__( 'Save Changes', 'chekideh' ), // The text for the options-page save button. Defaults to 'Save'.
		'tab_group'               => 'plugin_options', // Tab-group identifier, enables options page tab navigation.
		'tab_title'               => esc_html__( 'Style', 'chekideh' ), // Falls back to 'title' (above).
	) );
    $cmb_style_options->add_field( array(
        'name'    => esc_html__( 'Text Color', 'chekideh' ),
		'desc'    => esc_html__( 'Text color of the comments summary box.', 'chekideh' ),
        'id'      => 'text_color',
        'type'    => 'colorpicker',
        'default' => '#000000',
    ) );
    $cmb_style_options->add_field( array(
        'name'    => esc_html__( 'Background Color', 'chekideh' ),
		'desc'    => esc_html__( 'Background color of the comments summary box.', 'chekideh' ),
        'id'      => 'box_color',
        'type'    => 'colorpicker',
        'default' => '#f1f1f1',
    ) );
	$cmb_style_options->add_field( array(
		'name'    => esc_html__( 'Border Radius', 'chekideh' ),
		'desc'    => esc_html__( 'Border raduis of the comments summary box.', 'chekideh' ),
		'id'      => 'border_radius',
		'type'    => 'text_small',
		'default' => '5',
	) );
    $cmb_style_options->add_field( array(
        'name'    => esc_html__( 'Border Color', 'chekideh' ),
		'desc'    => esc_html__( 'Border color of the comments summary box.', 'chekideh' ),
        'id'      => 'border_color',
        'type'    => 'colorpicker',
        'default' => '#f1f1f1',
    ) );
    $cmb_style_options->add_field( array(
		'name'    => esc_html__( 'Top Margin', 'chekideh' ),
		'desc'    => esc_html__( 'Top margin of the comments summary box. (in px)', 'chekideh' ),
		'id'      => 'margin_top',
		'type'    => 'text_small',
		'default' => '0',
	) );
    $cmb_style_options->add_field( array(
		'name'    => esc_html__( 'Bottom Margin', 'chekideh' ),
		'desc'    => esc_html__( 'Bottom margin of the comments summary box. (in px)', 'chekideh' ),
		'id'      => 'margin_bottom',
		'type'    => 'text_small',
		'default' => '0',
	) );
    $cmb_style_options->add_field( array(
		'name'    => esc_html__( 'Custom CSS', 'chekideh' ),
		'desc'    => esc_html__( 'Enter your custom CSS code if needed.', 'chekideh' ),
		'id'      => 'custom_css',
		'type'    => 'textarea',
		'default' => '',
	) );
}

// Get response from openai models
function chekideh_get_openai_llm_response($user_message) {
	$open_ai_key = cmb2_get_option( 'chekideh_plugin_options', 'openai_api_key', '' );
	$open_ai = new OpenAi($open_ai_key);

    $base_url = cmb2_get_option( 'chekideh_plugin_options', 'openai_base_url', '' );

	$open_ai->setBaseURL( $base_url );

    // Prepare the chat request
    $chat = $open_ai->chat([
        'model' => cmb2_get_option( 'chekideh_plugin_options', 'openai_model_name', '' ),
        'messages' => [
            [
                "role" => "system",
                "content" => cmb2_get_option( 'chekideh_plugin_options', 'openai_system_prompt', '' ),
            ],
            [
                "role" => "user",
                "content" => $user_message // Use the input parameter here
            ],
        ],
        'temperature' => floatval(cmb2_get_option( 'chekideh_plugin_options', 'creativity', '0.5' )),
        'max_tokens' => intval(cmb2_get_option( 'chekideh_plugin_options', 'openai_max_tokens', '4000' )),
        'frequency_penalty' => 0,
        'presence_penalty' => 0,
    ]);

    // Decode the response
    $decoded_response = json_decode($chat);
	// error_log($decoded_response->error->message);

    // Check if the response is valid and contains the expected content
    if (isset($decoded_response->choices[0]->message->content)) {
		return new ChekidehStructuredResult(true, $decoded_response->choices[0]->message->content);
    } else {
        return new ChekidehStructuredResult(false, null, "Couldn't get a response.");
    }
}

add_action('wp', 'chekideh_setup_comments_summary');

function chekideh_setup_comments_summary() {
    if (is_single() && (get_post_type() === 'post' || get_post_type() === 'product')) {
        // Enqueue jQuery if it's not already included
        wp_enqueue_script('jquery');

        // Using inline JS in order not to load another file
        $chekideh_javascript = "
        jQuery(document).ready(function($) {
            var commentsSummary = document.querySelector('div.chekideh-comments-summary');
            if (!commentsSummary) return;

            if (chekideh_vars.post_type === 'post') {
                // Move summary to top of WordPress post comments area
                var commentsArea = commentsSummary.parentNode;
                commentsArea.removeChild(commentsSummary);
                commentsArea.insertBefore(commentsSummary, commentsArea.firstChild);
            } else if (chekideh_vars.post_type === 'product') {
                // Move summary to top of WooCommerce reviews list
                var reviewsArea = document.querySelector('#reviews .woocommerce-Reviews, #reviews .commentlist, #reviews');
                if (reviewsArea) {
                    commentsSummary.parentNode.removeChild(commentsSummary);
                    reviewsArea.insertBefore(commentsSummary, reviewsArea.firstChild);
                }
            }
        });
        ";

        // For differentiating between post and product in JS
        wp_localize_script('jquery', 'chekideh_vars', [
            'post_type' => get_post_type()
        ]);

        wp_add_inline_script('jquery', $chekideh_javascript);

        add_action('comment_form_before', 'chekideh_add_comments_summary');
    }
}

function chekideh_add_comments_summary() {
    $post_id = get_the_ID();
    $transient_key = 'chekideh_comments_summary_' . $post_id;
    // delete_transient($transient_key);

    // Check if summary exists in transient
    if (false === ($summary = get_transient($transient_key))) {
        $comments = get_comments(array('post_id' => $post_id));
        $comments_count = count($comments);

        if ($comments_count < intval(cmb2_get_option( 'chekideh_plugin_options', 'minimum_comments_count', '10' ))) {
            # Do not show the summary box
            return;
        } else {
            // Set blank transient in order not to schedule multiple times
            set_transient($transient_key, __( 'Generating the summary...', 'chekideh' ), 300);
            // Extract only the comment_content from the comments
            $comment_contents = array_map(function($comment) {
                return $comment->comment_content;
            }, $comments);
            // Concatenate comment contents with a delimiter (e.g., a newline or a special character)
            $comment_contents_string = implode("||DELIMITER||", $comment_contents);
            // Schedule the task
            as_enqueue_async_action('chekideh_generate_summary_task', array(
                $transient_key,
                $comment_contents_string,
            ));
        }
    }

    if (!$summary) {
        return;
    };

    // Style options
    $text_color = cmb2_get_option( 'chekideh_style_options', 'text_color', '#000000' );
    $box_color = cmb2_get_option( 'chekideh_style_options', 'box_color', '#f1f1f1' );
    $border_radius = cmb2_get_option( 'chekideh_style_options', 'border_radius', '5' );
    $border_color = cmb2_get_option( 'chekideh_style_options', 'border_color', '#f1f1f1' );
    $margin_top = cmb2_get_option( 'chekideh_style_options', 'margin_top', '0' );
    $margin_bottom = cmb2_get_option( 'chekideh_style_options', 'margin_bottom', '0' );

    // Using inline CSS in order not to load another file
    echo "
        <style>
            div.chekideh-comments-summary {
                color: {$text_color};
                background: {$box_color};
                border-radius: {$border_radius}px;
                border-color: {$border_color};
                padding: 25px 25px 25px 25px;
                min-width: 100%;
                margin-top: {$margin_top}px;
                margin-bottom: {$margin_bottom}px;
            }

            .chekideh-credit {
                font-size: 12px;
                color: #777;
                margin-top: 20px;
                display: flex;
                align-items: center;
            }

            .chekideh-credit a {
                text-decoration: none;
                color: inherit;
                display: flex;
                align-items: center;
            }

            .chekideh-credit img.chekideh-logo {
                height: 16px;
                width: auto;
                margin-right: 5px;
                vertical-align: middle;
            }
            " . cmb2_get_option( 'chekideh_style_options', 'custom_css', '' ) . "
        </style>
    ";

    echo '<div class="chekideh-comments-summary">' . $summary . '<div class="chekideh-credit">
    <a href="https://cache.cool/chekideh" target="_blank" rel="noopener"> <img src="/wp-content/plugins/chekideh/assets/images/box_icon.png"
             alt="Chekideh" class="chekideh-logo">
        <span>Summary generated by Chekideh</span>
    </a>
</div>' . '</div>';
}

function chekideh_generate_summary($transient_key, $comments) {
    $chekideh_summary_prompt = 
        cmb2_get_option( 'chekideh_plugin_options', 'openai_comments_summary_prompt', 'Please review the user comments provided below and create a clear, well-structured summary. The summary should:

        Highlight the main positive points mentioned by users.
        Highlight the main negative points mentioned by users.
        Identify any recurring patterns or important themes that appear across multiple comments.
        The goal is to give a concise and useful overview of users\' experiences.

        The output must be in HTML format using only the following tags: <p>, <ul>, and <li>.

        Do not include triple backticks or any additional formatting outside of the HTML.

        Write in a neutral and professional tone that is easy to understand.')
        . ' Comments List:'
        . $comments;
    $summary = chekideh_get_openai_llm_response($chekideh_summary_prompt);

    if ( !$summary->success ) {
		return;
	}

    // Store the summary in a transient
    set_transient($transient_key, $summary->data, intval(cmb2_get_option( 'chekideh_plugin_options', 'minimum_comments_count', '10' )) * 86400);

    return $summary->data;
}

// The callback function that performs the task
add_action('chekideh_generate_summary_task', 'chekideh_generate_summary', 2, 2);

// Load Plugin settings only in administrative interface page.
if(is_admin()){
    add_action( 'cmb2_admin_init', 'chekideh_register_plugin_options_metabox' );
}