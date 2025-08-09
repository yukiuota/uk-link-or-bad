<?php
/**
 * Plugin Name: UK Like or Bad
 * Description: ショートコードで「参考になった / 参考にならなかった」ボタンを表示し、クリック数をカウントします。文言は管理画面で変更可能。Cookieにより一定期間の再投票を抑止。
 * Version: 1.2.0
 * Author: Y.U.
 * Text Domain: uk-like-or-bad
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class UK_Like_Or_Bad {
    const OPTION_KEY = 'uklob_settings';
    const NONCE_ACTION = 'uklob_vote_action';
    const COOKIE_PREFIX = 'uklob_voted_';

    public function __construct() {
        // デフォルト設定の登録
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );

        // アセット
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // ショートコード
        add_shortcode( 'uk_like_or_bad', [ $this, 'render_shortcode' ] );

        // AJAX エンドポイント（ログイン有無に関わらず）
        add_action( 'wp_ajax_uklob_vote', [ $this, 'handle_vote' ] );
        add_action( 'wp_ajax_nopriv_uklob_vote', [ $this, 'handle_vote' ] );

    // 管理画面
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

    // 一覧カラム（全公開ポストタイプに適用）
    add_action( 'init', [ $this, 'register_admin_columns_for_all' ], 20 );
    add_action( 'pre_get_posts', [ $this, 'admin_handle_sorting' ] );
    // カウントリセット系
    add_action( 'admin_post_uklob_reset_counts', [ $this, 'handle_single_reset' ] );
    add_action( 'admin_notices', [ $this, 'admin_notice_reset' ] );
    }

    public function on_activate() {
        $defaults = [
            'label_like' => '参考になった',
            'label_bad' => '参考にならなかった',
            'cookie_days' => 7,
            'thank_you_message' => '投票ありがとうございました',
        ];
        $current = get_option( self::OPTION_KEY );
        if ( ! $current ) {
            add_option( self::OPTION_KEY, $defaults );
        } else {
            update_option( self::OPTION_KEY, wp_parse_args( $current, $defaults ) );
        }
    }

    public function get_settings() {
        $defaults = [
            'label_like' => '参考になった',
            'label_bad' => '参考にならなかった',
            'cookie_days' => 7,
            'thank_you_message' => '投票ありがとうございました',
        ];
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
    }

    public function enqueue_assets() {
        $handle = 'uklob';
        wp_register_style( $handle, plugins_url( 'assets/css/uklob.css', __FILE__ ), [], '1.0.0' );
        wp_register_script( $handle, plugins_url( 'assets/js/uklob.js', __FILE__ ), [ 'jquery' ], '1.0.0', true );

        $settings = $this->get_settings();
        $cookie_days = max( 1, intval( $settings['cookie_days'] ) );

        wp_localize_script( $handle, 'UKLOB', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'cookieDays' => $cookie_days,
            'thankYouMessage' => $settings['thank_you_message'],
        ] );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'post_id' => get_the_ID(),
        ], $atts, 'uk_like_or_bad' );

        $post_id = intval( $atts['post_id'] );
        if ( $post_id <= 0 ) return '';

        $settings = $this->get_settings();
        $like_count = intval( get_post_meta( $post_id, '_uklob_like', true ) );
        $bad_count  = intval( get_post_meta( $post_id, '_uklob_bad', true ) );

        // 再読込時にも投票済みを即時反映（FOUC防止）
        $cookie_key = self::COOKIE_PREFIX . $post_id;
        $voted_type = isset( $_COOKIE[ $cookie_key ] ) ? sanitize_key( wp_unslash( $_COOKIE[ $cookie_key ] ) ) : '';
        if ( ! in_array( $voted_type, [ 'like', 'bad' ], true ) ) {
            $voted_type = '';
        }

        $wrap_classes = [ 'uklob' ];
        $btn_like_classes = [ 'uklob-btn', 'uklob-like' ];
        $btn_bad_classes  = [ 'uklob-btn', 'uklob-bad' ];
        $disabled_attr = '';
        $aria_disabled = 'false';
        $title_attr = '';

        if ( $voted_type ) {
            $wrap_classes[] = 'uklob-disabled';
            $wrap_classes[] = 'uklob-voted';
            $wrap_classes[] = 'uklob-voted-' . $voted_type;
            $disabled_attr = ' disabled="disabled"';
            $aria_disabled = 'true';
            $title_attr = ' 投票済みのため、この投稿には投票できません';
            if ( 'like' === $voted_type ) {
                $btn_like_classes[] = 'uklob-btn-voted';
            } elseif ( 'bad' === $voted_type ) {
                $btn_bad_classes[] = 'uklob-btn-voted';
            }
        }

        wp_enqueue_style( 'uklob' );
        wp_enqueue_script( 'uklob' );

        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $wrap_classes ) ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
            <button class="<?php echo esc_attr( implode( ' ', $btn_like_classes ) ); ?>" data-type="like" aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" title="<?php echo esc_attr( $title_attr ); ?>"<?php echo $disabled_attr; ?>>
                <span class="uklob-label"><?php echo esc_html( $settings['label_like'] ); ?></span>
                <span class="uklob-count" aria-live="polite"><?php echo esc_html( $like_count ); ?></span>
            </button>
            <button class="<?php echo esc_attr( implode( ' ', $btn_bad_classes ) ); ?>" data-type="bad" aria-disabled="<?php echo esc_attr( $aria_disabled ); ?>" title="<?php echo esc_attr( $title_attr ); ?>"<?php echo $disabled_attr; ?>>
                <span class="uklob-label"><?php echo esc_html( $settings['label_bad'] ); ?></span>
                <span class="uklob-count" aria-live="polite"><?php echo esc_html( $bad_count ); ?></span>
            </button>
            <p class="uklob-thanks" aria-live="polite" hidden></p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_vote() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        // 入力のサニタイズ
        $post_id = isset( $_POST['postId'] ) ? absint( wp_unslash( $_POST['postId'] ) ) : 0;
        $type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

        if ( $post_id <= 0 || ! in_array( $type, [ 'like', 'bad' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid params' ], 400 );
        }

        // 投稿の存在と公開性を確認（非公開・下書き等への投票は不可）
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found' ], 404 );
        }
        if ( 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
            wp_send_json_error( [ 'message' => 'Not allowed' ], 403 );
        }

        // Cookie ベースのブロック（サーバー側の軽い確認）
        $cookie_key = self::COOKIE_PREFIX . $post_id;
        if ( isset( $_COOKIE[ $cookie_key ] ) ) {
            wp_send_json_error( [ 'message' => 'Already voted' ], 429 );
        }

        $meta_key = $type === 'like' ? '_uklob_like' : '_uklob_bad';
        $count    = intval( get_post_meta( $post_id, $meta_key, true ) );
        $count++;
        update_post_meta( $post_id, $meta_key, $count );

        $like_count = intval( get_post_meta( $post_id, '_uklob_like', true ) );
        $bad_count  = intval( get_post_meta( $post_id, '_uklob_bad', true ) );

        // Cookie 設定（日数はフロント JS 側でも設定するが、念のためサーバーでも発行）
        $settings = $this->get_settings();
        $days = max( 1, intval( $settings['cookie_days'] ) );
        $expire = time() + DAY_IN_SECONDS * $days;
        // Cookieを安全に設定（HttpOnly + SameSite=Lax）
        $cookie_params = [
            'expires'  => $expire,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie( $cookie_key, $type, $cookie_params );

        wp_send_json_success( [
            'like' => $like_count,
            'bad'  => $bad_count,
        ] );
    }

    public function add_settings_page() {
        add_options_page(
            'UK Like or Bad',
            'UK Like or Bad',
            'manage_options',
            'uklob-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'uklob_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        add_settings_section( 'uklob_main', '基本設定', '__return_false', 'uklob-settings' );

        add_settings_field( 'label_like', '「参考になった」ラベル', [ $this, 'field_label_like' ], 'uklob-settings', 'uklob_main' );
        add_settings_field( 'label_bad', '「参考にならなかった」ラベル', [ $this, 'field_label_bad' ], 'uklob-settings', 'uklob_main' );
    add_settings_field( 'cookie_days', '再投票を禁止する日数', [ $this, 'field_cookie_days' ], 'uklob-settings', 'uklob_main' );
    add_settings_field( 'thank_you_message', '投票後メッセージ', [ $this, 'field_thank_you_message' ], 'uklob-settings', 'uklob_main' );
    }

    public function sanitize_settings( $input ) {
        $out = [];
        $out['label_like'] = isset( $input['label_like'] ) ? sanitize_text_field( $input['label_like'] ) : '参考になった';
        $out['label_bad']  = isset( $input['label_bad'] ) ? sanitize_text_field( $input['label_bad'] ) : '参考にならなかった';
    $out['cookie_days'] = isset( $input['cookie_days'] ) ? max( 1, intval( $input['cookie_days'] ) ) : 7;
    $out['thank_you_message'] = isset( $input['thank_you_message'] ) ? sanitize_textarea_field( $input['thank_you_message'] ) : '投票ありがとうございました';
        return $out;
    }

    public function field_label_like() {
        $v = $this->get_settings()['label_like'];
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[label_like]" value="' . esc_attr( $v ) . '" class="regular-text" />';
    }

    public function field_label_bad() {
        $v = $this->get_settings()['label_bad'];
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[label_bad]" value="' . esc_attr( $v ) . '" class="regular-text" />';
    }

    public function field_cookie_days() {
        $v = intval( $this->get_settings()['cookie_days'] );
        echo '<input type="number" min="1" name="' . esc_attr( self::OPTION_KEY ) . '[cookie_days]" value="' . esc_attr( $v ) . '" class="small-text" /> 日';
    }

    public function field_thank_you_message() {
        $v = $this->get_settings()['thank_you_message'];
        echo '<textarea name="' . esc_attr( self::OPTION_KEY ) . '[thank_you_message]" rows="2" class="large-text" placeholder="投票ありがとうございました">' . esc_textarea( $v ) . '</textarea>';
        echo '<p class="description">ボタン押下後に表示するメッセージ。空にすると表示しません。</p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>UK Like or Bad</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'uklob_group' ); ?>
                <?php do_settings_sections( 'uklob-settings' ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * 全公開ポストタイプ（attachment除く）に一覧カラムを登録
     */
    public function register_admin_columns_for_all() {
        $types = get_post_types( [ 'public' => true ], 'names' );
        foreach ( $types as $type ) {
            if ( 'attachment' === $type ) { continue; }

            add_filter( "manage_edit-{$type}_columns", [ $this, 'admin_add_post_columns' ] );
            add_action( "manage_{$type}_posts_custom_column", [ $this, 'admin_render_post_column' ], 10, 2 );
            add_filter( "manage_edit-{$type}_sortable_columns", [ $this, 'admin_sortable_post_columns' ] );
            // 行アクション
            add_filter( "{$type}_row_actions", [ $this, 'add_reset_row_action' ], 10, 2 );
            // 一括アクション
            add_filter( "bulk_actions-edit-{$type}", [ $this, 'register_bulk_action' ] );
            add_filter( "handle_bulk_actions-edit-{$type}", [ $this, 'handle_bulk_action' ], 10, 3 );
        }
    }

    /**
     * 投稿一覧テーブルのカラムを追加
     */
    public function admin_add_post_columns( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'date' === $key ) {
                $new['uklob_like'] = '参考になった';
                $new['uklob_bad']  = '参考にならなかった';
            }
        }
        // フォールバック（dateがない場合は末尾に追加）
        if ( ! isset( $new['uklob_like'] ) ) {
            $new['uklob_like'] = '参考になった';
            $new['uklob_bad']  = '参考にならなかった';
        }
        return $new;
    }

    /**
     * 追加カラムの表示
     */
    public function admin_render_post_column( $column, $post_id ) {
        if ( 'uklob_like' === $column ) {
            echo intval( get_post_meta( $post_id, '_uklob_like', true ) );
        } elseif ( 'uklob_bad' === $column ) {
            echo intval( get_post_meta( $post_id, '_uklob_bad', true ) );
        }
    }

    /**
     * ソート可能カラムの登録
     */
    public function admin_sortable_post_columns( $columns ) {
        $columns['uklob_like'] = 'uklob_like';
        $columns['uklob_bad']  = 'uklob_bad';
        return $columns;
    }

    /**
     * ソート処理（メタ値で並び替え）
     */
    public function admin_handle_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // 投稿一覧画面のみ
        global $pagenow;
        if ( 'edit.php' !== $pagenow ) {
            return;
        }

        $orderby = $query->get( 'orderby' );
        if ( 'uklob_like' === $orderby ) {
            $query->set( 'meta_key', '_uklob_like' );
            $query->set( 'orderby', 'meta_value_num' );
        } elseif ( 'uklob_bad' === $orderby ) {
            $query->set( 'meta_key', '_uklob_bad' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }

    /* ==========================
     * リセット関連
     * ========================== */

    /**
     * 行アクション: 個別リセットリンクを追加
     */
    public function add_reset_row_action( $actions, $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }
        $nonce_action = 'uklob_reset_counts_' . $post->ID;
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=uklob_reset_counts&post=' . $post->ID ), $nonce_action );
        $actions['uklob_reset'] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'カウントをリセットしますか？\');">L/Bリセット</a>';
        return $actions;
    }

    /**
     * 単体リセット処理 (admin-post)
     */
    public function handle_single_reset() {
        if ( ! isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( 'Missing post id' );
        }
        $post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $post_id ) {
            wp_die( 'Invalid post id' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Permission denied' );
        }
        check_admin_referer( 'uklob_reset_counts_' . $post_id );
        $this->reset_counts_for_post( $post_id );
        $redirect = add_query_arg( [ 'uklob_reset' => 1, 'reset_count' => 1 ], wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * 一括アクション登録
     */
    public function register_bulk_action( $bulk_actions ) {
        $bulk_actions['uklob_reset_counts'] = 'UK Like/Bad カウントをリセット';
        return $bulk_actions;
    }

    /**
     * 一括アクション処理
     */
    public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
        if ( 'uklob_reset_counts' !== $doaction ) {
            return $redirect_to;
        }
        $count = 0;
        foreach ( (array) $post_ids as $post_id ) {
            if ( current_user_can( 'edit_post', $post_id ) ) {
                $this->reset_counts_for_post( $post_id );
                $count++;
            }
        }
        $redirect_to = add_query_arg( [ 'uklob_reset' => $count ], $redirect_to );
        return $redirect_to;
    }

    /**
     * 実際のリセット処理
     */
    private function reset_counts_for_post( $post_id ) {
        update_post_meta( $post_id, '_uklob_like', 0 );
        update_post_meta( $post_id, '_uklob_bad', 0 );
    }

    /**
     * 成功メッセージ表示
     */
    public function admin_notice_reset() {
        if ( ! isset( $_GET['uklob_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $num = intval( $_GET['uklob_reset'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $num < 1 ) { return; }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( '%d件のカウントをリセットしました。', $num ) ) . '</p></div>';
    }
}

new UK_Like_Or_Bad();
