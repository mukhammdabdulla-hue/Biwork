<?php
declare(strict_types=1);

/** Mahsulotning ochiq reklama sahifasi (/<slug>), shuningdek admin uchun oldindan ko'rish. */
final class ProductPage
{
    /**
     * @param string $mode live — hamma ko'radi; hidden — yashirin mahsulotni xodim ko'ryapti; preview — saqlanmagan forma qiymatlari
     */
    public static function render(array $t, string $err = '', array $in = [], int $status = 200, string $mode = 'live'): never
    {
        $m = $t['marketing'];
        $plans = $mode === 'preview' && (int)$t['id'] === 0 ? [] : Catalog::plans((int)$t['id']);
        $slug = (string)$t['slug'];
        $title = ($m['seo']['title'] ?? '') !== '' ? $m['seo']['title'] : $t['name'] . ' — ' . ($t['tagline'] !== '' ? mb_strtolower(mb_substr($t['tagline'], 0, 1)) . mb_substr($t['tagline'], 1) : 'Biwork mahsuloti');
        $para = mb_substr(trim((string)(preg_split('/\n{2,}/', trim($t['description']))[0] ?? '')), 0, 170);
        $thin = mb_strlen($para) < mb_strlen($t['name']) + 12; // tavsif faqat nomning o'zi bo'lsa (hali yozilmagan) — qisqa tavsifga tayanamiz
        $fallback = $t['name'] . ($t['tagline'] !== '' ? ' — ' . $t['tagline'] : '') . ". " . (trim((string)$t['web_url']) !== '' ? "Brauzerda sinab ko'ring yoki so'rov qoldiring." : "So'rov qoldiring — bog'lanib, korxonangizga mosligini aniqlaymiz.");
        $desc = ($m['seo']['description'] ?? '') !== '' ? $m['seo']['description']
            : ((($m['lead'] ?? '') !== '') ? mb_substr($m['lead'], 0, 180) : ($thin ? $fallback : $para));
        $url = Util::absUrl('/' . $slug);
        $og = Marketing::ogImage($t);
        $app = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $t['name'], 'description' => $desc, 'url' => $url,
            'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Windows, Linux', 'inLanguage' => 'uz',
            'image' => $og, 'publisher' => ['@type' => 'Organization', 'name' => 'Biwork', 'url' => Util::absUrl('/')]];
        $offers = [];
        foreach ($plans as $pl) {
            if ($pl['price'] !== null && (int)$pl['price'] > 0) {
                $offers[] = ['@type' => 'Offer', 'name' => $pl['name'], 'price' => (int)$pl['price'], 'priceCurrency' => $pl['currency'], 'url' => $url];
            }
        }
        if ($offers) {
            $app['offers'] = $offers;
        }
        $ld = [$app, ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Biwork', 'item' => Util::absUrl('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Mahsulotlar', 'item' => Util::absUrl('/mahsulotlar')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $t['name'], 'item' => $url]]]];
        if (!empty($m['faq'])) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => [
                '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $m['faq'])];
        }
        $seo = ['title' => $title, 'description' => $desc, 'canonical' => $url, 'image' => $og, 'type' => 'website',
            'keywords' => implode(', ', $m['seo']['keywords'] ?? []), 'jsonld' => $ld, 'canonical' => $url];
        if ($mode !== 'live') {
            $seo['noindex'] = true;
        }
        View::page('product', ['t' => $t, 'm' => $m, 'plans' => $plans, 'user' => Auth::user(), 'leadErr' => $err, 'in' => $in, 'mode' => $mode],
            ['area' => 'public', 'title' => $t['name'], 'seo' => $seo], $status);
    }
}
