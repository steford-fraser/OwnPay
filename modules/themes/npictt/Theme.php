<?php
declare(strict_types=1);

namespace OwnPay\Modules\Themes\Npictt;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * NPICTT white-label theme - implements PluginInterface.
 * Registers checkout templates and assets.
 */
final class Theme implements PluginInterface
{
    public static function metadata(): array
    {
        return [
            'name'        => 'NPICTT Theme',
            'slug'        => 'npictt',
            'version'     => '1.0.0',
            'description' => 'White-label theme for The National Payment and Innovation Company of Trinidad and Tobago.',
            'author'      => 'NPICTT',
            'type'        => 'theme',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::THEME];
    }

    public function register(EventManager $events, Container $container): void
    {
        // Register checkout template paths
        $events->addFilter('checkout.template', function (string $template): string {
            return 'checkout/checkout.twig';
        });

        $events->addFilter('checkout.status.template', function (string $template): string {
            return 'checkout/checkout-status.twig';
        });

        $events->addFilter('checkout.payment_link.template', function (string $template): string {
            return 'checkout/payment-link-amount.twig';
        });

        // Register landing page features
        $events->addFilter('landing.features', function (array $features): array {
            return $features;
        });

        // Enqueue assets
        $events->addAction('checkout.head', function (): void {
            echo '<link rel="stylesheet" href="/assets/css/checkout.css">';
        });

        $events->addAction('checkout.footer', function () use ($container): void {
            $nonceVal = $container->has('csp_nonce') ? $container->get('csp_nonce') : '';
            $nonceAttr = is_string($nonceVal) && $nonceVal !== '' ? ' nonce="' . htmlspecialchars($nonceVal, ENT_QUOTES, 'UTF-8') . '"' : '';
            echo '<script' . $nonceAttr . ' src="/assets/js/op-fetch.js"></script>';
            echo '<script' . $nonceAttr . ' src="/assets/js/checkout.js"></script>';
        });
    }

    public function boot(Container $container): void
    {
        $twig = $container->get(\Twig\Environment::class);
        if (!$twig instanceof \Twig\Environment) {
            return;
        }
        $loader = $twig->getLoader();
        $templatesDir = __DIR__ . '/templates';
        if ($loader instanceof \Twig\Loader\FilesystemLoader && is_dir($templatesDir)) {
            $loader->prependPath($templatesDir);
        }

        // Some payer pages (e.g. /pay/{slug}) only receive a merchant id, not the `brand` array.
        try {
            $twig->addFunction(new \Twig\TwigFunction('npictt_brand', static function (mixed $merchantId) use ($container): array {
                $id = is_numeric($merchantId) ? (int) $merchantId : 0;
                if ($id <= 0 || !$container->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
                    return [];
                }
                $service = $container->get(\OwnPay\Service\Brand\BrandThemeService::class);
                return $service instanceof \OwnPay\Service\Brand\BrandThemeService ? $service->getBrandTheme($id) : [];
            }));
            $twig->addFunction(new \Twig\TwigFunction('npictt_gateway_label', static function (mixed $slug, mixed $merchantId) use ($container): string {
                $slug = is_string($slug) ? $slug : '';
                $registry = $container->has(\OwnPay\Plugin\PluginRegistry::class) ? $container->get(\OwnPay\Plugin\PluginRegistry::class) : null;
                $plugin = $registry instanceof \OwnPay\Plugin\PluginRegistry ? ($registry->getLoaded()[$slug] ?? null) : null;
                if (is_object($plugin) && is_callable([$plugin, 'statusLabel'])) {
                    return (string) $plugin->statusLabel();
                }
                if (is_object($plugin) && is_callable([$plugin, 'checkoutLabel'])) {
                    return (string) $plugin->checkoutLabel(is_numeric($merchantId) ? (int) $merchantId : 0);
                }
                return $slug;
            }));
            $twig->addFunction(new \Twig\TwigFunction('npictt_ministries', static function () use ($container): array {
                $db = $container->has(\OwnPay\Core\Database::class) ? $container->get(\OwnPay\Core\Database::class) : null;
                if (!$db instanceof \OwnPay\Core\Database) {
                    return [];
                }
                // Brand 1 is NPICTT itself; only ministry brands are listed publicly.
                $ministries = $db->fetchAll(
                    "SELECT m.id, m.name, m.slug, m.logo_path, m.initials, m.description, pl.slug AS pay_slug
                     FROM op_merchants m
                     LEFT JOIN op_payment_links pl
                       ON pl.merchant_id = m.id AND pl.slug = CONCAT(m.slug, '-pay') AND pl.status = 'active'
                     WHERE m.status = 'active' AND m.is_platform = 0 AND m.id <> 1
                     ORDER BY m.name"
                );
                if ($ministries === []) {
                    return [];
                }

                $ids = implode(',', array_map(static fn(array $m): int => (int) $m['id'], $ministries));
                $links = $db->fetchAll(
                    "SELECT pl.merchant_id, pl.slug, pl.title, pl.amount, pl.currency, pl.is_amount_fixed
                     FROM op_payment_links pl
                     JOIN op_merchants m ON m.id = pl.merchant_id
                     WHERE pl.merchant_id IN ({$ids}) AND pl.status = 'active'
                       AND pl.slug <> CONCAT(m.slug, '-pay')
                       AND (pl.expires_at IS NULL OR pl.expires_at > NOW(6))
                       AND (pl.max_uses IS NULL OR pl.use_count < pl.max_uses)
                     ORDER BY pl.id"
                );
                $byMerchant = [];
                foreach ($links as $link) {
                    $byMerchant[(int) $link['merchant_id']][] = $link;
                }
                foreach ($ministries as &$m) {
                    $m['links'] = array_slice($byMerchant[(int) $m['id']] ?? [], 0, 3);
                }
                unset($m);
                return $ministries;
            }));
        } catch (\LogicException) {
            // Twig extensions already initialised; templates fall back to NPICTT-only branding.
        }
    }
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function fields(): array
    {
        return [
            [
                'name'    => 'primary_color',
                'label'   => 'Primary Color',
                'type'    => 'color',
                'default' => '#0003B1',
                'help'    => 'Main brand color for checkout pages.',
            ],
            [
                'name'    => 'accent_color',
                'label'   => 'Accent Color',
                'type'    => 'color',
                'default' => '#E4002B',
                'help'    => 'Accent color for buttons and highlights.',
            ],
            [
                'name'    => 'checkout_logo',
                'label'   => 'Checkout Logo URL',
                'type'    => 'text',
                'default' => '',
                'help'    => 'URL to logo displayed on checkout pages.',
            ],
            [
                'name'    => 'show_powered_by',
                'label'   => 'Show "Powered by" credit',
                'type'    => 'toggle',
                'default' => '1',
            ],
        ];
    }

    /**
     * Safe brand color - regex validated, falls back to NPICTT blue.
     */
    public static function safeBrandColor(string $color = ''): string
    {
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }
        return '#0003B1';
    }
}
