<?php
/**
 * Plugin Name: FWERKOR SMTP
 * Description: Minimal SMTP transport for FWERKOR WordPress sites.
 * Version: 1.0.0
 * Author: FWERKOR
 * License: MIT
 */

if (!defined('ABSPATH')) { exit; }

final class FWERKOR_SMTP {
    private const OPTION = 'fwerkor_smtp_options';

    public static function init(): void {
        add_action('phpmailer_init', [self::class, 'configure_mailer'], 20);
        add_filter('wp_mail_from', [self::class, 'mail_from']);
        add_filter('wp_mail_from_name', [self::class, 'mail_from_name']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'admin_init']);
    }

    public static function activate(): void {
        if (get_option(self::OPTION)) {
            return;
        }

        $renamed = get_option('fwerkor_mail_options', null);
        if (is_array($renamed) && $renamed) {
            update_option(self::OPTION, $renamed, false);
            return;
        }

        self::migrate_legacy();
    }

    private static function defaults(): array {
        return [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'force_from_name' => false,
            'timeout' => 20,
        ];
    }

    public static function options(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    private static function migrate_legacy(): void {
        $current = (array) get_option('easy_wp_smtp', []);
        $current_smtp = (array) ($current['smtp'] ?? []);
        $current_mail = (array) ($current['mail'] ?? []);

        if (
            ($current_mail['mailer'] ?? '') === 'smtp' &&
            !empty($current_smtp['host'])
        ) {
            $password = '';
            if (class_exists('\\EasyWPSMTP\\Options')) {
                try {
                    $password = (string) \EasyWPSMTP\Options::init()->get('smtp', 'pass');
                } catch (Throwable $e) {
                    $password = '';
                }
            }

            $encryption = (string) ($current_smtp['encryption'] ?? 'tls');
            if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
                $encryption = 'tls';
            }

            update_option(self::OPTION, [
                'host' => sanitize_text_field((string) ($current_smtp['host'] ?? '')),
                'port' => max(1, min(65535, (int) ($current_smtp['port'] ?? 587))),
                'encryption' => $encryption,
                'auth' => !empty($current_smtp['auth']),
                'username' => sanitize_text_field((string) ($current_smtp['user'] ?? '')),
                'password' => $password,
                'from_email' => sanitize_email((string) ($current_mail['from_email'] ?? '')),
                'from_name' => sanitize_text_field((string) ($current_mail['from_name'] ?? '')),
                'force_from_name' => !empty($current_mail['from_name_force']),
                'timeout' => 20,
            ], false);
            return;
        }

        $legacy = (array) get_option('swpsmtp_options', []);
        if (!$legacy) {
            $legacy = (array) get_option('easy_wp_smtp_options', []);
        }

        $smtp = (array) ($legacy['smtp_settings'] ?? []);
        if (!$smtp) {
            return;
        }

        $encryption = (string) ($smtp['type_encryption'] ?? '');
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        update_option(self::OPTION, [
            'host' => sanitize_text_field((string) ($smtp['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($smtp['port'] ?? 587))),
            'encryption' => $encryption,
            'auth' => in_array(strtolower((string) ($smtp['autentication'] ?? 'yes')), ['yes', 'true', '1', 'on'], true),
            'username' => sanitize_text_field((string) ($smtp['username'] ?? '')),
            'password' => self::decode_legacy_password((string) ($smtp['password'] ?? '')),
            'from_email' => sanitize_email((string) ($legacy['from_email_field'] ?? '')),
            'from_name' => sanitize_text_field((string) ($legacy['from_name_field'] ?? '')),
            'force_from_name' => !empty($legacy['force_from_name_replace']),
            'timeout' => 20,
        ], false);
    }

    private static function decode_legacy_password(string $password): string {
        if ($password === '') {
            return '';
        }

        if (get_option('swpsmtp_pass_encrypted') && class_exists('EasyWPSMTP_Utils')) {
            try {
                return stripslashes((string) EasyWPSMTP_Utils::get_instance()->decrypt_password($password));
            } catch (Throwable $e) {
                return '';
            }
        }

        $decoded = base64_decode($password, true);
        if ($decoded !== false && base64_encode($decoded) === $password) {
            return stripslashes($decoded);
        }

        return stripslashes($password);
    }

    public static function configure_mailer($mailer): void {
        $o = self::options();
        if (!$o['host']) {
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = (string) $o['host'];
        $mailer->Port = (int) $o['port'];
        $mailer->SMTPAuth = (bool) $o['auth'];
        $mailer->Username = (string) $o['username'];
        $mailer->Password = defined('FWERKOR_SMTP_PASSWORD') ? (string) FWERKOR_SMTP_PASSWORD : (string) $o['password'];
        $mailer->Timeout = (int) $o['timeout'];
        $mailer->SMTPAutoTLS = true;

        if ($o['encryption'] === 'ssl') {
            $mailer->SMTPSecure = 'ssl';
        } elseif ($o['encryption'] === 'tls') {
            $mailer->SMTPSecure = 'tls';
        } else {
            $mailer->SMTPSecure = '';
        }

        // Never relax TLS certificate verification.
        $mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
    }

    public static function mail_from(string $email): string {
        $configured = (string) self::options()['from_email'];
        return $configured && is_email($configured) ? $configured : $email;
    }

    public static function mail_from_name(string $name): string {
        $o = self::options();
        if ($o['from_name'] && ($o['force_from_name'] || !$name)) {
            return (string) $o['from_name'];
        }
        return $o['from_name'] ?: $name;
    }

    public static function admin_menu(): void {
        add_options_page('FWERKOR SMTP', 'FWERKOR SMTP', 'manage_options', 'fwerkor-smtp', [self::class, 'settings_page']);
    }

    public static function admin_init(): void {
        register_setting('fwerkor_smtp', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($value): array {
        $old = self::options();
        $v = is_array($value) ? $value : [];
        $enc = (string) ($v['encryption'] ?? 'tls');

        return [
            'host' => sanitize_text_field((string) ($v['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($v['port'] ?? 587))),
            'encryption' => in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls',
            'auth' => !empty($v['auth']),
            'username' => sanitize_text_field((string) ($v['username'] ?? '')),
            'password' => (string) ($v['password'] ?? '') !== '' ? (string) $v['password'] : (string) $old['password'],
            'from_email' => sanitize_email((string) ($v['from_email'] ?? '')),
            'from_name' => sanitize_text_field((string) ($v['from_name'] ?? '')),
            'force_from_name' => !empty($v['force_from_name']),
            'timeout' => max(5, min(60, (int) ($v['timeout'] ?? 20))),
        ];
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $o = self::options();
        ?>
        <div class="wrap">
            <h1>FWERKOR SMTP</h1>
            <form method="post" action="options.php">
                <?php settings_fields('fwerkor_smtp'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>SMTP host</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[host]" value="<?php echo esc_attr($o['host']); ?>"></td></tr>
                    <tr><th>Port</th><td><input type="number" name="<?php echo esc_attr(self::OPTION); ?>[port]" value="<?php echo (int) $o['port']; ?>"></td></tr>
                    <tr><th>Encryption</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[encryption]">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'Implicit TLS', 'none' => 'None'] as $k => $label) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($o['encryption'], $k); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                    <tr><th>Authentication</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auth]" value="1" <?php checked($o['auth']); ?>> Enable SMTP AUTH</label></td></tr>
                    <tr><th>Username</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[username]" value="<?php echo esc_attr($o['username']); ?>"></td></tr>
                    <tr><th>Password</th><td><input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[password]" value="" placeholder="Leave blank to keep current password"></td></tr>
                    <tr><th>From email</th><td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[from_email]" value="<?php echo esc_attr($o['from_email']); ?>"></td></tr>
                    <tr><th>From name</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[from_name]" value="<?php echo esc_attr($o['from_name']); ?>"></td></tr>
                    <tr><th>Force from name</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[force_from_name]" value="1" <?php checked($o['force_from_name']); ?>> Replace application-supplied name</label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

FWERKOR_SMTP::init();
register_activation_hook(__FILE__, [FWERKOR_SMTP::class, 'activate']);
