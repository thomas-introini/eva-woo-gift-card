<?php
// File: wp-content/plugins/eva-gift-cards/src/Admin/SettingsPage.php

namespace Eva\GiftCards\Admin;

defined('ABSPATH') || exit;

class SettingsPage
{

  /**
   * Register hooks.
   *
   * @return void
   */
  public function hooks(): void
  {
    add_action('admin_init', array($this, 'register_settings'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_color_picker'));
  }

  /**
   * Register submenu page under WooCommerce.
   *
   * @return void
   */
  public function register_menu(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    add_submenu_page(
      'woocommerce',
      __('Stile box carta regalo', 'eva-gift-cards'),
      __('Gift Card - Stile', 'eva-gift-cards'),
      'manage_woocommerce',
      'eva-gift-cards-style',
      array($this, 'render_page')
    );
  }

  /**
   * Register settings and fields.
   *
   * @return void
   */
  public function register_settings(): void
  {
    $option_group = 'eva_gift_cards';
    $page         = 'eva_gift_cards_style';

    // Settings.
    register_setting(
      $option_group,
      'eva_gc_box_bg_color',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#f0f8ff',
      )
    );
    register_setting(
      $option_group,
      'eva_gc_box_border_color',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#0073aa',
      )
    );
    register_setting(
      $option_group,
      'eva_gc_apply_btn_bg_color',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#0073aa',
      )
    );
    register_setting(
      $option_group,
      'eva_gc_apply_btn_text_color',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#ffffff',
      )
    );
    register_setting(
      $option_group,
      'eva_gc_remove_btn_color',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#d63638',
      )
    );
    register_setting(
      $option_group,
      'eva_gc_custom_css',
      array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_strip_all_tags',
        'default'           => '',
      )
    );

    // Section.
    add_settings_section(
      'eva_gc_style_section',
      __('Stile del box in checkout', 'eva-gift-cards'),
      function () {
        echo '<p>' . esc_html__('Configura i colori e gli stili personalizzati del box carta regalo mostrato in checkout.', 'eva-gift-cards') . '</p>';
      },
      $page
    );

    // Fields.
    add_settings_field(
      'eva_gc_box_bg_color',
      __('Colore sfondo box', 'eva-gift-cards'),
      array($this, 'render_color_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option' => 'eva_gc_box_bg_color',
      )
    );

    add_settings_field(
      'eva_gc_box_border_color',
      __('Colore bordo box', 'eva-gift-cards'),
      array($this, 'render_color_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option' => 'eva_gc_box_border_color',
      )
    );

    add_settings_field(
      'eva_gc_apply_btn_bg_color',
      __('Colore pulsante Applica (sfondo)', 'eva-gift-cards'),
      array($this, 'render_color_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option' => 'eva_gc_apply_btn_bg_color',
      )
    );

    add_settings_field(
      'eva_gc_apply_btn_text_color',
      __('Colore pulsante Applica (testo)', 'eva-gift-cards'),
      array($this, 'render_color_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option' => 'eva_gc_apply_btn_text_color',
      )
    );

    add_settings_field(
      'eva_gc_remove_btn_color',
      __('Colore bottone rimozione (bordo/testo)', 'eva-gift-cards'),
      array($this, 'render_color_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option' => 'eva_gc_remove_btn_color',
      )
    );

    add_settings_field(
      'eva_gc_custom_css',
      __('CSS Personalizzato', 'eva-gift-cards'),
      array($this, 'render_textarea_field'),
      $page,
      'eva_gc_style_section',
      array(
        'option'       => 'eva_gc_custom_css',
        'placeholder'  => '/* Aggiungi qui il tuo CSS personalizzato */',
        'description'  => __('Questi stili verranno caricati solo nella pagina di checkout.', 'eva-gift-cards'),
      )
    );
  }

  /**
   * Enqueue color picker assets on our settings page.
   *
   * @param string $hook Hook suffix.
   * @return void
   */
  public function enqueue_color_picker($hook): void
  {
    // Load on the Carte regalo page (tabs) and on the standalone style page.
    if ('woocommerce_page_eva-gift-cards-style' !== $hook && 'woocommerce_page_eva-gift-cards' !== $hook) {
      return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    // Initialize color pickers.
    wp_add_inline_script(
      'wp-color-picker',
      "( function( $ ) { $( function() { $( '.eva-gc-color-field' ).wpColorPicker(); } ); } )( jQuery );"
    );
  }

  /**
   * Render color picker field.
   *
   * @param array $args Args.
   * @return void
   */
  public function render_color_field($args): void
  {
    $option = isset($args['option']) ? $args['option'] : '';
    $value  = get_option($option, '');
?>
    <input type="text" class="eva-gc-color-field" name="<?php echo esc_attr($option); ?>" value="<?php echo esc_attr($value); ?>" data-default-color="<?php echo esc_attr($value); ?>" />
  <?php
  }

  /**
   * Render textarea field.
   *
   * @param array $args Args.
   * @return void
   */
  public function render_textarea_field($args): void
  {
    $option      = isset($args['option']) ? $args['option'] : '';
    $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
    $description = isset($args['description']) ? $args['description'] : '';
    $value       = get_option($option, '');
  ?>
    <textarea name="<?php echo esc_attr($option); ?>" rows="8" cols="60" placeholder="<?php echo esc_attr($placeholder); ?>" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($value); ?></textarea>
    <?php if ($description) : ?>
      <p class="description"><?php echo esc_html($description); ?></p>
    <?php endif; ?>
  <?php
  }

  /**
   * Render page content.
   *
   * @return void
   */
  public function render_page(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      wp_die(esc_html__('Non hai i permessi sufficienti per accedere a questa pagina.', 'eva-gift-cards'));
    }
  ?>
    <div class="wrap">
      <h1><?php echo esc_html(__('Stile box carta regalo (Checkout)', 'eva-gift-cards')); ?></h1>
      <form method="post" action="options.php">
        <?php
        settings_fields('eva_gift_cards');
        do_settings_sections('eva_gift_cards_style');
        submit_button();
        ?>
      </form>
    </div>
<?php
  }
}
