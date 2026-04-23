<?php
/*
Plugin Name: AI Post Summary — Ultimate Customizer Pro
Plugin URI: https://juanrafaelruiz.com/
Description: Resúmenes con ChatGPT. Control total de diseño, Google Fonts y administración dinámica.
Version: 5.0
Author: Juan Rafael Ruiz
License: GPL2+
Text Domain: cgpts_complete
*/

if (!defined('ABSPATH')) exit;

class CGPTS_Complete {

    const OPTION_KEY = 'cgpts_complete_settings';
    const META_KEY_SUMMARY = '_cgpts_summary';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta_box_data']);
        add_action('wp_ajax_cgpts_generate_single', [$this, 'ajax_generate_single']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('chatgpt_summary_button', [$this, 'render_button_shortcode']);
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('update_option_' . self::OPTION_KEY, [$this, 'clear_models_cache'], 10, 2);
    }

    /* =========================
       CONFIGURACIÓN Y VALORES
    ========================= */

    public function get_defaults() {
        return [
            'api_key' => '',
            'model' => 'gpt-4o-mini',
            'summary_length' => 'medium',
            'custom_prompt' => "Resume el siguiente artículo de forma clara y profesional. {{LONGITUD}}\n\nTítulo: {{TITULO}}\n\nContenido:\n{{CONTENIDO}}\n\nResumen:",
            'btn_txt_closed' => 'Resumir con ChatGPT',
            'btn_txt_opened' => 'Ocultar resumen',
            'btn_color' => '#ffffff',
            'btn_bg' => '#cc1b35',
            'btn_bg_hover' => '#000000',
            'btn_bg_active' => '#333333',
            'btn_font_family' => 'inherit',
            'btn_font_size' => '14px',
            'btn_font_weight' => '600',
            'btn_border_width' => '1px',
            'btn_border_style' => 'solid',
            'btn_border_color' => '#cc1b35',
            'btn_border_color_hover' => '#000000',
            'btn_border_radius' => '4px',
            'cont_bg' => '#fefefe',
            'cont_radius' => '8px',
            'cont_m_t' => '20px', 'cont_m_r' => '0px', 'cont_m_b' => '20px', 'cont_m_l' => '0px',
            'cont_p_t' => '25px', 'cont_p_r' => '25px', 'cont_p_b' => '25px', 'cont_p_l' => '25px',
            'cont_b_w' => '1px', 'cont_b_s' => 'solid', 'cont_b_c' => '#e0e0e0',
            'head_tag' => 'h4',
            'head_font_family' => 'inherit',
            'head_font_size' => '18px',
            'head_font_weight' => '700',
            'head_line_height' => '1.2',
            'head_color' => '#333333',
            'head_m_t' => '0px', 'head_m_r' => '0px', 'head_m_b' => '15px', 'head_m_l' => '0px',
            'head_p_t' => '0px', 'head_p_r' => '0px', 'head_p_b' => '10px', 'head_p_l' => '0px',
            'head_b_w' => '0px', 'head_b_s' => 'solid', 'head_b_c' => '#cc1b35',
            'text_font_family' => 'inherit',
            'text_font_size' => '15px',
            'text_font_weight' => '400',
            'text_line_height' => '1.7',
            'text_color' => '#444444',
        ];
    }

    public function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $this->get_defaults());
    }

    public function get_api_key() {
        $settings = $this->get_settings();
        return !empty($settings['api_key']) ? trim($settings['api_key']) : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    }

    /* =========================
       MODELOS DINÁMICOS
    ========================= */

    private function get_openai_models() {
        $api_key = $this->get_api_key();
        if (empty($api_key)) return [];
        $cache_key = 'cgpts_models_cache';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 10
        ]);
        if (is_wp_error($response)) return [];
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['data'])) return [];
        $models = [];
        foreach ($body['data'] as $m) {
            if (strpos($m['id'], 'gpt-') === 0) $models[$m['id']] = $m['id'];
        }
        ksort($models);
        set_transient($cache_key, $models, DAY_IN_SECONDS);
        return $models;
    }

    public function clear_models_cache($old, $new) {
        if (($old['api_key'] ?? '') !== ($new['api_key'] ?? '')) {
            delete_transient('cgpts_models_cache');
        }
    }

    /* =========================
       ADMIN UI HELPERS
    ========================= */

    public function get_google_fonts() {
        return ['inherit' => 'Fuente Activa del Tema', 'Montserrat' => 'Montserrat', 'Roboto' => 'Roboto', 'Open Sans' => 'Open Sans', 'Lato' => 'Lato', 'Poppins' => 'Poppins', 'Oswald' => 'Oswald', 'Lora' => 'Lora', 'Raleway' => 'Raleway'];
    }

    public function get_font_weights() {
        return ['100'=>'100','200'=>'200','300'=>'300','400'=>'400','500'=>'500','600'=>'600','700'=>'700','800'=>'800','900'=>'900'];
    }

    public function render_input($key, $type = 'text', $options = []) {
        $s = $this->get_settings();
        $name = self::OPTION_KEY . "[$key]";
        $val = esc_attr($s[$key]);

        if ($type === 'select') {
            echo "<select name='$name' style='width:100%'>";
            foreach($options as $k => $v) echo "<option value='$k' ".selected($val,$k,false).">$v</option>";
            echo "</select>";
        } elseif ($type === 'color') {
            echo "<input type='color' name='$name' value='$val' style='width:50px; height:30px;' />";
        } elseif ($type === 'textarea') {
            echo "<textarea name='$name' rows='5' style='width:100%; font-family:monospace;'>$val</textarea>";
        } else {
            echo "<input type='$type' name='$name' value='$val' style='width:100%' />";
        }
    }

    public function render_box_model($prefix, $label) {
        $s = $this->get_settings();
        $dirs = ['t' => 'Top', 'r' => 'Right', 'b' => 'Bottom', 'l' => 'Left'];
        echo "<strong>$label:</strong><div style='display:flex; gap:5px; margin-top:5px;'>";
        foreach($dirs as $d => $txt) {
            $k = "{$prefix}_{$d}";
            echo "<span><small>$txt</small><br><input type='text' name='".self::OPTION_KEY."[$k]' value='".esc_attr($s[$k])."' style='width:55px;' /></span>";
        }
        echo "</div>";
    }

    public function render_border_full($prefix) {
        $s = $this->get_settings();
        echo "<div style='display:flex; gap:10px; align-items:end;'>";
        echo "<span><small>Grosor</small><br><input type='text' name='".self::OPTION_KEY."[{$prefix}_b_w]' value='".esc_attr($s["{$prefix}_b_w"])."' style='width:60px;' /></span>";
        echo "<span><small>Estilo</small><br><select name='".self::OPTION_KEY."[{$prefix}_b_s]'>";
        foreach(['none','solid','dashed','dotted','double'] as $st) echo "<option value='$st' ".selected($s["{$prefix}_b_s"],$st,false).">$st</option>";
        echo "</select></span>";
        echo "<span><small>Color</small><br><input type='color' name='".self::OPTION_KEY."[{$prefix}_b_c]' value='".esc_attr($s["{$prefix}_b_c"])."' /></span>";
        echo "</div>";
    }

    /* =========================
       PÁGINA DE AJUSTES
    ========================= */

    public function admin_menu() {
        add_options_page('ChatGPT Summarizer', 'ChatGPT Summarizer', 'manage_options', 'cgpts-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('cgpts_group', self::OPTION_KEY);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Configuración ChatGPT Summarizer</h1>
            <h2 class="nav-tab-wrapper">
                <a href="#tab-api" class="nav-tab nav-tab-active">Configuración API</a>
                <a href="#tab-app" class="nav-tab">Apariencia Botón y Resultado</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('cgpts_group'); ?>

                <div id="tab-api" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th>OpenAI API Key</th>
                            <td><?php $this->render_input('api_key', 'password'); ?></td>
                        </tr>
                        <tr>
                            <th>Modelo de IA</th>
                            <td>
                                <?php 
                                $models = $this->get_openai_models();
                                if ($models) {
                                    $this->render_input('model', 'select', $models);
                                } else {
                                    echo '<p style="color:#cc1b35">Guarda tu API Key para listar modelos disponibles.</p>';
                                    $this->render_input('model');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Longitud del Resumen</th>
                            <td><?php $this->render_input('summary_length', 'select', [
                                'very-short' => 'Muy corto (1 frase, ~30 palabras)',
                                'short' => 'Corto (2-3 frases, ~80 palabras)',
                                'medium' => 'Medio (1 párrafo, ~150 palabras)',
                                'long' => 'Largo (2-3 párrafos, ~300 palabras)',
                                'complete' => 'Completo (Resumen exhaustivo)'
                            ]); ?></td>
                        </tr>
                        <tr>
                            <th>Prompt Personalizado</th>
                            <td>
                                <?php $this->render_input('custom_prompt', 'textarea'); ?>
                                <p class="description">Variables: <code>{{LONGITUD}}</code>, <code>{{TITULO}}</code>, <code>{{CONTENIDO}}</code></p>
                            </td>
                        </tr>
                    </table>

                    <!-- INFO SHORTCODE -->
                    <div style="margin-top:40px; padding:25px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1;">
                        <h3 style="margin-top:0;">Cómo usar el plugin</h3>
                        <p>Para mostrar el resumen en tus entradas, pega el siguiente shortcode en el editor de WordPress (Gutenberg o Clásico):</p>
                        <code style="display:inline-block; font-size:16px; padding:10px; background:#f0f0f1; border:1px solid #ccc; margin:10px 0;">[chatgpt_summary_button]</code>
                        <p class="description" style="margin-top:10px;">
                            <strong>¿Cómo funciona?</strong> Este shortcode mostrará únicamente el botón configurado. 
                            Cuando el visitante haga clic, la IA generará el resumen (si no ha sido generado antes) y se desplegará el contenedor con el resultado debajo del botón automáticamente.
                        </p>
                    </div>
                </div>

                <div id="tab-app" class="tab-content" style="display:none;">
                    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px;">
                        <h3>1. Estilos del Botón</h3>
                        <table class="form-table">
                            <tr><th>Textos</th><td>
                                <small>Texto Cerrado:</small><br><?php $this->render_input('btn_txt_closed'); ?><br><br>
                                <small>Texto Abierto:</small><br><?php $this->render_input('btn_txt_opened'); ?>
                            </td></tr>
                            <tr><th>Tipografía y Colores</th><td>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <span><small>Fuente</small><br><?php $this->render_input('btn_font_family', 'select', $this->get_google_fonts()); ?></span>
                                    <span><small>Tamaño</small><br><?php $this->render_input('btn_font_size'); ?></span>
                                    <span><small>Peso</small><br><?php $this->render_input('btn_font_weight', 'select', $this->get_font_weights()); ?></span>
                                    <span><small>Color Texto</small><br><?php $this->render_input('btn_color', 'color'); ?></span>
                                </div>
                                <div style="display:flex; gap:10px; margin-top:15px;">
                                    <span><small>Fondo Base</small><br><?php $this->render_input('btn_bg', 'color'); ?></span>
                                    <span><small>Fondo Hover</small><br><?php $this->render_input('btn_bg_hover', 'color'); ?></span>
                                    <span><small>Fondo Activo</small><br><?php $this->render_input('btn_bg_active', 'color'); ?></span>
                                </div>
                            </td></tr>
                            <tr><th>Bordes</th><td>
                                <?php $this->render_border_full('btn'); ?>
                                <div style="margin-top:10px; display:flex; gap:10px;">
                                    <span><small>Color Borde Hover</small><br><?php $this->render_input('btn_border_color_hover', 'color'); ?></span>
                                    <span><small>Radio Esquinas</small><br><?php $this->render_input('btn_border_radius'); ?></span>
                                </div>
                            </td></tr>
                        </table>
                    </div>

                    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px;">
                        <h3>2. Contenedor de Resultado</h3>
                        <table class="form-table">
                            <tr><th>Espaciado</th><td>
                                <?php $this->render_box_model('cont_m', 'Margen (External)'); ?><br>
                                <?php $this->render_box_model('cont_p', 'Padding (Internal)'); ?>
                            </td></tr>
                            <tr><th>Diseño</th><td>
                                <small>Fondo:</small> <?php $this->render_input('cont_bg', 'color'); ?><br><br>
                                <?php $this->render_border_full('cont'); ?><br>
                                <small>Radio:</small> <?php $this->render_input('cont_radius'); ?>
                            </td></tr>
                        </table>
                    </div>

                    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px;">
                        <h3>3. Título (Header)</h3>
                        <table class="form-table">
                            <tr><th>Tipografía</th><td>
                                <div style="display:flex; gap:10px;">
                                    <span><small>Tag</small><br><?php $this->render_input('head_tag', 'select', ['h1'=>'h1','h2'=>'h2','h3'=>'h3','h4'=>'h4','div'=>'div','p'=>'p']); ?></span>
                                    <span><small>Fuente</small><br><?php $this->render_input('head_font_family', 'select', $this->get_google_fonts()); ?></span>
                                    <span><small>Tamaño</small><br><?php $this->render_input('head_font_size'); ?></span>
                                    <span><small>Peso</small><br><?php $this->render_input('head_font_weight', 'select', $this->get_font_weights()); ?></span>
                                    <span><small>Color</small><br><?php $this->render_input('head_color', 'color'); ?></span>
                                </div>
                            </td></tr>
                            <tr><th>Separador y Espacio</th><td>
                                <?php $this->render_box_model('head_m', 'Margen'); ?><br>
                                <?php $this->render_box_model('head_p', 'Padding'); ?><br>
                                <small>Borde Inferior:</small><br><?php $this->render_border_full('head'); ?>
                            </td></tr>
                        </table>
                    </div>

                    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px;">
                        <h3>4. Texto del Resumen</h3>
                        <table class="form-table">
                            <tr><th>Tipografía</th><td>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <span><small>Fuente</small><br><?php $this->render_input('text_font_family', 'select', $this->get_google_fonts()); ?></span>
                                    <span><small>Tamaño</small><br><?php $this->render_input('text_font_size'); ?></span>
                                    <span><small>Peso</small><br><?php $this->render_input('text_font_weight', 'select', $this->get_font_weights()); ?></span>
                                    <span><small>Line-height</small><br><?php $this->render_input('text_line_height'); ?></span>
                                    <span><small>Color</small><br><?php $this->render_input('text_color', 'color'); ?></span>
                                </div>
                            </td></tr>
                        </table>
                    </div>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('.nav-tab').click(function(e){
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
        });
        </script>
        <?php
    }

    /* =========================
       FRONTEND ASSETS (CSS)
    ========================= */

    public function enqueue_frontend_assets() {
        if (!is_singular('post')) return;
        $s = $this->get_settings();
        $fonts = array_unique([$s['btn_font_family'], $s['head_font_family'], $s['text_font_family']]);
        $load = [];
        foreach($fonts as $f) if($f !== 'inherit') $load[] = str_replace(' ', '+', $f) . ':100,200,300,400,500,600,700,800,900';
        if($load) wp_enqueue_style('cgpts-gfonts', 'https://fonts.googleapis.com/css?family=' . implode('|', $load));

        wp_register_script('cgpts-js', false, [], '5.0', true);
        wp_enqueue_script('cgpts-js');
        wp_add_inline_script('cgpts-js', "var CGPTS_API = '" . esc_url_raw(rest_url('cgpts/v3/summarize')) . "'; " . $this->get_js_logic());

        wp_register_style('cgpts-style', false);
        wp_enqueue_style('cgpts-style');
        wp_add_inline_style('cgpts-style', $this->generate_css($s));
    }

    private function generate_css($s) {
        $f_btn = $s['btn_font_family'] === 'inherit' ? 'inherit' : "'{$s['btn_font_family']}'";
        $f_head = $s['head_font_family'] === 'inherit' ? 'inherit' : "'{$s['head_font_family']}'";
        $f_text = $s['text_font_family'] === 'inherit' ? 'inherit' : "'{$s['text_font_family']}'";
        return "
        .cgpts-btn {
            display: inline-flex; align-items: center; justify-content: center;
            font-family: $f_btn; font-size: {$s['btn_font_size']}; font-weight: {$s['btn_font_weight']};
            color: {$s['btn_color']}; background-color: {$s['btn_bg']};
            border: {$s['btn_border_width']} {$s['btn_border_style']} {$s['btn_border_color']};
            border-radius: {$s['btn_border_radius']}; padding: 12px 25px; cursor: pointer; transition: 0.3s;
        }
        .cgpts-btn:hover { background-color: {$s['btn_bg_hover']}; border-color: {$s['btn_border_color_hover']}; }
        .cgpts-btn.active { background-color: {$s['btn_bg_active']}; }
        .cgpts-result-container {
            display: none; background-color: {$s['cont_bg']};
            margin: {$s['cont_m_t']} {$s['cont_m_r']} {$s['cont_m_b']} {$s['cont_m_l']};
            padding: {$s['cont_p_t']} {$s['cont_p_r']} {$s['cont_p_b']} {$s['cont_p_l']};
            border: {$s['cont_b_w']} {$s['cont_b_s']} {$s['cont_b_c']};
            border-radius: {$s['cont_radius']};
            animation: cgptsFade 0.4s ease;
        }
        @keyframes cgptsFade { from{opacity:0; transform:translateY(-5px)} to{opacity:1; transform:translateY(0)} }
        .cgpts-header-tag {
            font-family: $f_head; font-size: {$s['head_font_size']}; font-weight: {$s['head_font_weight']};
            color: {$s['head_color']}; line-height: {$s['head_line_height']};
            margin: {$s['head_m_t']} {$s['head_m_r']} {$s['head_m_b']} {$s['head_m_l']};
            padding: {$s['head_p_t']} {$s['head_p_r']} {$s['head_p_b']} {$s['head_p_l']};
            border-bottom: {$s['head_b_w']} {$s['head_b_s']} {$s['head_b_c']};
        }
        .cgpts-result-content { font-family: $f_text; font-size: {$s['text_font_size']}; font-weight: {$s['text_font_weight']}; color: {$s['text_color']}; line-height: {$s['text_line_height']}; }
        .cgpts-result-content p { margin-bottom: 1em; }";
    }

    public function render_button_shortcode() {
        global $post;
        if (!$post || $post->post_type !== 'post') return '';
        $s = $this->get_settings();
        $summary = get_post_meta($post->ID, self::META_KEY_SUMMARY . '_es', true);
        ob_start();
        ?>
        <div class="cgpts-wrapper" data-post-id="<?php echo $post->ID; ?>" data-closed="<?php echo esc_attr($s['btn_txt_closed']); ?>" data-opened="<?php echo esc_attr($s['btn_txt_opened']); ?>">
            <button class="cgpts-btn" onclick="cgptsToggle(this)"><span class="cgpts-btn-txt"><?php echo esc_html($s['btn_txt_closed']); ?></span></button>
            <div id="cgpts-res-<?php echo $post->ID; ?>" class="cgpts-result-container">
                <<?php echo $s['head_tag']; ?> class="cgpts-header-tag"><?php echo get_the_title($post->ID); ?></<?php echo $s['head_tag']; ?>>
                <div class="cgpts-result-content"><?php echo $summary ? wpautop($summary) : ''; ?></div>
                <div class="cgpts-loader" style="display:none; color: #666; font-style: italic; margin-top: 10px;">Generando resumen...</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_js_logic() {
        return "
        function cgptsToggle(btn) {
            var wrap = btn.closest('.cgpts-wrapper');
            var pid = wrap.dataset.postId;
            var res = document.getElementById('cgpts-res-' + pid);
            var content = res.querySelector('.cgpts-result-content');
            var loader = res.querySelector('.cgpts-loader');
            var txt = btn.querySelector('.cgpts-btn-txt');
            if (res.style.display === 'block') {
                res.style.display = 'none'; btn.classList.remove('active'); txt.textContent = wrap.dataset.closed;
            } else {
                res.style.display = 'block'; btn.classList.add('active'); txt.textContent = wrap.dataset.opened;
                if (content.innerHTML.trim() === '') {
                    loader.style.display = 'block';
                    fetch(CGPTS_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({post_id: pid}) })
                    .then(r => r.json()).then(data => {
                        loader.style.display = 'none';
                        if (data.success) content.innerHTML = data.summary;
                        else content.innerHTML = '<p style=\"color:red\">' + data.message + '</p>';
                    });
                }
            }
        }";
    }

    /* =========================
       API Y LOGICA DE RESUMEN
    ========================= */

    public function generate_summary_for_post($post_id) {
        $s = $this->get_settings();
        $api_key = $this->get_api_key();
        if (!$api_key) return new WP_Error('no_key', 'Falta API Key');
        $post = get_post($post_id);
        $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
        if (mb_strlen($content) > 10000) $content = mb_substr($content, 0, 10000);

        $prompt = str_replace(['{{LONGITUD}}', '{{TITULO}}', '{{CONTENIDO}}'], [$s['summary_length'], get_the_title($post_id), $content], $s['custom_prompt']);
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60, 'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $s['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.7])
        ]);
        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $summary = $body['choices'][0]['message']['content'] ?? '';
        if ($summary) update_post_meta($post_id, self::META_KEY_SUMMARY . '_es', trim($summary));
        return $summary ? $summary : new WP_Error('fail', 'Error en la respuesta de IA');
    }

    public function register_rest_routes() {
        register_rest_route('cgpts/v3', '/summarize', [
            'methods' => 'POST',
            'callback' => function($request) {
                $pid = intval($request->get_param('post_id'));
                $res = $this->generate_summary_for_post($pid);
                if (is_wp_error($res)) return ['success' => false, 'message' => $res->get_error_message()];
                return ['success' => true, 'summary' => wpautop($res)];
            },
            'permission_callback' => '__return_true'
        ]);
    }

    /* =========================
       METABOX ADMIN (SOLO SI HAY KEY)
    ========================= */

    public function add_meta_box() {
        if (empty($this->get_api_key())) return; // OCULTAR SI NO HAY KEY
        add_meta_box('cgpts_mb', '🤖 ChatGPT Resumen', [$this, 'render_metabox'], 'post', 'normal', 'high');
    }

    public function render_metabox($post) {
        $val = get_post_meta($post->ID, self::META_KEY_SUMMARY . '_es', true);
        wp_nonce_field('cgpts_meta_nonce', 'cgpts_nonce');
        ?>
        <div class="cgpts-admin-metabox">
            <textarea name="cgpts_summary_manual" id="cgpts_summary_manual" style="width:100%" rows="8"><?php echo esc_textarea($val); ?></textarea>
            <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                <button type="button" class="button button-primary" id="cgpts-admin-gen" data-post-id="<?php echo $post->ID; ?>">⚡ Generar / Regenerar con IA</button>
                <button type="submit" class="button">💾 Guardar manual</button>
                <span id="cgpts-admin-spinner" class="spinner"></span>
            </div>
            <div id="cgpts-admin-msg" style="margin-top:10px;"></div>
        </div>
        <?php
    }

    public function save_meta_box_data($post_id) {
        if (!isset($_POST['cgpts_nonce']) || !wp_verify_nonce($_POST['cgpts_nonce'], 'cgpts_meta_nonce')) return;
        if (isset($_POST['cgpts_summary_manual'])) {
            update_post_meta($post_id, self::META_KEY_SUMMARY . '_es', wp_kses_post($_POST['cgpts_summary_manual']));
        }
    }

    public function ajax_generate_single() {
        check_ajax_referer('cgpts_admin_nonce', 'nonce');
        $pid = intval($_POST['post_id']);
        $res = $this->generate_summary_for_post($pid);
        if (is_wp_error($res)) wp_send_json_error($res->get_error_message());
        wp_send_json_success(['summary' => $res]);
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;
        wp_enqueue_script('cgpts-admin-js', false, ['jquery'], '5.0', true);
        $nonce = wp_create_nonce('cgpts_admin_nonce');
        wp_add_inline_script('cgpts-admin-js', "
            jQuery(document).ready(function($){
                $('#cgpts-admin-gen').click(function(){
                    var btn = $(this), spinner = $('#cgpts-admin-spinner'), msg = $('#cgpts-admin-msg');
                    btn.prop('disabled', true); spinner.addClass('is-active');
                    $.post(ajaxurl, {action:'cgpts_generate_single', post_id:btn.data('post-id'), nonce:'$nonce'}, function(r){
                        if(r.success){ $('#cgpts_summary_manual').val(r.data.summary); msg.html('<span style=\"color:green\">✅ Generado con éxito.</span>'); }
                        else { msg.html('<span style=\"color:red\">❌ Error: '+r.data+'</span>'); }
                        btn.prop('disabled', false); spinner.removeClass('is-active');
                    });
                });
            });
        ");
    }
}

new CGPTS_Complete();