<?php

namespace Blax\Shop\Tests\Feature\ShopServiceProvider;

use Blax\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationsTest extends TestCase
{
    protected function langDir(): string
    {
        return dirname(__DIR__, 3).'/lang';
    }

    #[Test]
    public function shop_namespace_is_registered()
    {
        $this->assertEquals('Added to cart.', __('shop::cart.added'));
        $this->assertEquals('Signed in.', __('shop::auth.login_ok'));
    }

    #[Test]
    public function de_and_pl_translate_every_english_key()
    {
        foreach (['cart', 'auth'] as $file) {
            $en = require $this->langDir()."/en/{$file}.php";

            foreach (['de', 'pl'] as $locale) {
                $path = $this->langDir()."/{$locale}/{$file}.php";
                $this->assertFileExists($path);
                $translated = require $path;

                $this->assertEqualsCanonicalizing(
                    array_keys($en),
                    array_keys($translated),
                    "lang/{$locale}/{$file}.php must carry exactly the keys of lang/en/{$file}.php"
                );

                foreach ($en as $key => $english) {
                    $this->assertNotSame('', trim((string) $translated[$key]), "{$locale}: {$file}.{$key} is empty");
                    $this->assertNotSame($english, $translated[$key], "{$locale}: {$file}.{$key} is still English");
                }
            }
        }
    }

    #[Test]
    public function locale_switch_resolves_de_and_pl_messages()
    {
        app()->setLocale('de');
        $this->assertEquals('In den Warenkorb gelegt.', __('shop::cart.added'));

        app()->setLocale('pl');
        $this->assertEquals('Dodano do koszyka.', __('shop::cart.added'));

        app()->setLocale('fr');
        $this->assertEquals('Added to cart.', __('shop::cart.added'), 'unknown locales fall back to en');
    }

    #[Test]
    public function apps_can_override_via_lang_vendor_shop()
    {
        $dir = $this->app->langPath('vendor/shop/de');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/cart.php', "<?php\nreturn ['added' => 'Liegt im Korb.'];\n");

        try {
            app()->setLocale('de');
            app('translator')->setLoaded([]);

            $this->assertEquals('Liegt im Korb.', __('shop::cart.added'));
            $this->assertEquals('Aus dem Warenkorb entfernt.', __('shop::cart.removed'), 'untouched keys keep the package text');
        } finally {
            @unlink($dir.'/cart.php');
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }
}
