<?php
declare(strict_types=1);
namespace App\UI;

final class UI
{
    /** Icon of the active workspace page; the app layout sets it so page headings match the navigation. */
    public static string $pageIcon = '';
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function attrs(array $attrs): string
    {
        $html = '';
        foreach ($attrs as $key => $value) {
            if ($value === false || $value === null || !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', (string)$key)) continue;
            $html .= ' ' . $key . ($value === true ? '' : '="' . self::e((string)$value) . '"');
        }
        return $html;
    }
    public static function icon(string $name, string $class = 'size-5'): string
    {
        $paths = [
            'droplet'=>'<path d="M12 2C9 6 4 10 4 15a8 8 0 0 0 16 0c0-5-5-9-8-13Z"/><path d="M8 16a4 4 0 0 0 4 4"/>',
            'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
            'pin'=>'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
            'orders'=>'<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h3"/>',
            'truck'=>'<path d="M2 5h12v13H2zM14 9h4l4 5v4h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
            'users'=>'<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 5v2"/>',
            'user'=>'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
            'bottle'=>'<path d="M9 2h6v4l3 3v12H6V9l3-3zM6 13h12M6 18h12M9 5h6"/>',
            'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6M17 2v6M3 11h18M7 15h2M13 15h2M7 18h2"/>',
            'chart'=>'<path d="M4 21V11M10 21V3M16 21V8M22 21H2"/>',
            'settings'=>'<path d="m10 3-1 3-3 1-3 3 2 3-1 3 3 3 3-1 3 2 3-3 1-3 3-1v-4l-3-1-1-3-3-2Z"/><circle cx="12" cy="12" r="3"/>',
            'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 9-3 9-3 10h18c0-1-3-1-3-10M10 22h4"/>',
            'search'=>'<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
            'message'=>'<path d="M21 11a9 9 0 0 1-9 9 11 11 0 0 1-4-1l-6 3 2-6a9 9 0 1 1 17-5Z"/><path d="M7 10h10M7 14h6"/>',
            'send'=>'<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'emoji'=>'<circle cx="12" cy="12" r="9"/><path d="M8 14s1 3 4 3 4-3 4-3M8 9h.01M16 9h.01"/>',
            'back'=>'<path d="M20 12H4m6-6-6 6 6 6"/>',
            'chevron'=>'<path d="m6 9 6 6 6-6"/>',
            'chevron-right'=>'<path d="m9 6 6 6-6 6"/>',
            'arrow-right'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
            'arrow-left'=>'<path d="M20 12H4m6-6-6 6 6 6"/>',
            'plus'=>'<path d="M12 5v14M5 12h14"/>',
            'minus'=>'<path d="M5 12h14"/>',
            'check'=>'<path d="m5 12 4 4L19 6"/>',
            'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
            'phone'=>'<path d="m7 3 3 5-3 3c2 3 4 5 7 6l3-3 5 3-2 5C10 22 2 14 2 5Z"/>',
            'mail'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/>',
            'eye'=>'<path d="M2 12s4-8 10-8 10 8 10 8-4 8-10 8S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
            'menu'=>'<path d="M3 6h18M3 12h18M3 18h18"/>',
            'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
            'logout'=>'<path d="M9 3H3v18h6M10 12h12m-5-5 5 5-5 5"/>',
            'shield'=>'<path d="M12 2 3 6v6c0 6 9 10 9 10s9-4 9-10V6Z"/><path d="m8 12 3 3 5-6"/>',
            'file'=>'<path d="M5 2h9l5 5v15H5zM14 2v6h5M8 12h8M8 16h8"/>',
            'activity'=>'<path d="M3 6h1M8 6h13M3 12h1M8 12h13M3 18h1M8 18h13"/>',
            'filter'=>'<path d="M3 4h18l-7 8v8l-4-2v-6z"/>',
            'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M3 16v5h18v-5"/>',
            'alert'=>'<path d="m12 3 10 18H2Z"/><path d="M12 9v5M12 17v1"/>',
            'edit'=>'<path d="m16 3 5 5-12 12-6 1 1-6zM13 6l5 5"/>',
            'store'=>'<path d="M3 10v12h18V10M2 10l3-7h14l3 7a3 3 0 0 1-5 2 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-5-2ZM9 22v-7h6v7"/>',
            'refresh'=>'<path d="M20 7a9 9 0 0 0-15-2L2 8m0-5v5h5M4 17a9 9 0 0 0 15 2l3-3m0 5v-5h-5"/>',
            'credit'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20M6 16h3"/>',
            'help'=>'<circle cx="12" cy="12" r="10"/><path d="M9 8a3 3 0 0 1 6 0c0 2-3 2-3 5M12 17h.01"/>',
            'login'=>'<path d="M15 3h6v18h-6M3 12h12m-5-5 5 5-5 5"/>',
            'lock'=>'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'tag'=>'<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        ];
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="'.self::e($class).'">'.($paths[$name] ?? $paths['droplet']).'</svg>';
    }
    public static function logo(string $class = 'h-9'): string
    {
        return '<img src="/assets/images/logo.png" alt="HydroRoute" width="585" height="160" class="w-auto '.self::e($class).'">';
    }
    public static function btn(string $label, string $href = '', string $variant = 'primary', string $icon = '', array $attrs = []): string
    {
        $attrs['class'] = 'btn btn-' . $variant . ' ' . ($attrs['class'] ?? '');
        $content = ($icon ? self::icon($icon, 'size-4.5 shrink-0') : '') . '<span>'.self::e($label).'</span>';
        if ($href !== '') return '<a href="'.self::e($href).'"'.self::attrs($attrs).'>'.$content.'</a>';
        $attrs['type'] ??= 'button';
        return '<button'.self::attrs($attrs).'>'.$content.'</button>';
    }
    public static function googleButton(string $toast): string
    {
        return '<button class="btn btn-ghost min-h-12 w-full text-[15px]" type="button" data-toast="'.self::e($toast).'"><svg class="size-5" viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285F4" d="M44 24.5c0-1.5-.1-3-.4-4.5H24v8h11.2a9.5 9.5 0 0 1-4.2 6.3v5.3h6.8C41.8 36 44 30.7 44 24.5Z"/><path fill="#34A853" d="M24 45c5.7 0 10.5-1.9 14-5.4l-6.8-5.3C29.3 35.5 26.9 36.2 24 36.2c-5.5 0-10.1-3.7-11.8-8.6H5.3V33C8.8 40.1 15.9 45 24 45Z"/><path fill="#FBBC05" d="M12.2 27.6a12.9 12.9 0 0 1 0-8V14H5.3a21.1 21.1 0 0 0 0 19Z"/><path fill="#EA4335" d="M24 11.2c3.1 0 5.9 1.1 8.1 3.1l6.1-6.1C34.5 4.7 29.7 3 24 3 15.9 3 8.8 7.9 5.3 15l6.9 5.4c1.7-5 6.3-9.2 11.8-9.2Z"/></svg><span>Continue with Google</span></button>';
    }
    public static function stat(string $label, string $value, string $icon, string $tone = 'blue', string $note = '', string $tag = ''): string
    {
        // Darker gradient stops keep white text readable.
        $tones = ['blue'=>'from-sky-600 to-blue-700 shadow-blue-700/20','teal'=>'from-cyan-600 to-teal-700 shadow-teal-700/20','green'=>'from-emerald-600 to-emerald-800 shadow-emerald-800/20','violet'=>'from-violet-600 to-purple-700 shadow-purple-700/20','red'=>'from-rose-600 to-red-700 shadow-red-700/20','amber'=>'from-amber-600 to-orange-700 shadow-orange-700/20'];
        $wave = '<svg class="pointer-events-none absolute bottom-10 right-0 h-9 w-28 text-white/35 max-sm:hidden" viewBox="0 0 140 48" fill="none" aria-hidden="true"><path d="M0 40c18 0 22-20 42-18s26 18 46 10 30-28 52-24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
        return '<article class="relative overflow-hidden rounded-xl bg-linear-to-br p-3.5 text-white shadow-md md:p-4 '.($tones[$tone] ?? $tones['blue']).'">'
            .'<span class="pointer-events-none absolute -right-6 -top-6 size-20 rounded-full bg-white/10" aria-hidden="true"></span>'.$wave
            .'<div class="relative flex items-start justify-between gap-2"><span class="flex size-9 items-center justify-center rounded-lg bg-white/20 ring-1 ring-white/30">'.self::icon($icon, 'size-4.5').'</span>'
            .($tag !== '' ? '<span class="inline-flex items-center gap-1 rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide">'.self::icon($icon, 'size-3').self::e($tag).'</span>' : '').'</div>'
            .'<p class="relative mt-3 text-xl font-bold leading-none sm:text-2xl">'.self::e($value).'</p>'
            .'<p class="relative mt-1.5 text-[11px] font-bold uppercase tracking-wider">'.self::e($label).'</p>'
            .($note !== '' ? '<p class="relative mt-2.5 border-t border-white/25 pt-2 text-xs">'.self::e($note).'</p>' : '')
            .'</article>';
    }
    public static function badge(string $label, string $tone = 'blue'): string
    {
        $tone = $tone === 'gray' ? 'slate' : $tone;
        $tones = ['green'=>'bg-emerald-50 text-emerald-800 ring-emerald-100','amber'=>'bg-amber-50 text-amber-800 ring-amber-100','red'=>'bg-rose-50 text-rose-800 ring-rose-100','slate'=>'bg-slate-100 text-slate-600 ring-slate-200','blue'=>'bg-sky-50 text-sky-800 ring-sky-100'];
        return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.($tones[$tone]??$tones['blue']).'"><span class="size-1.5 rounded-full bg-current opacity-70"></span>'.self::e($label).'</span>';
    }
    public static function field(string $label, string $name, string $value = '', string $type = 'text', array $attrs = []): string
    {
        $id = (string)($attrs['id'] ?? $name);
        $attrs = array_merge(['id'=>$id,'name'=>$name,'type'=>$type,'value'=>$value], $attrs);
        $icon = (string)($attrs['icon'] ?? '');
        $labelClass = (string)($attrs['label_class'] ?? '');
        $hint = (string)($attrs['hint'] ?? '');
        unset($attrs['icon'], $attrs['label_class'], $attrs['hint']);
        if ($hint !== '') $attrs['aria-describedby'] = $id.'-hint';
        $attrs['class'] = 'field ' . ($icon !== '' ? 'pl-10 ' : '') . ($attrs['class']??'');
        $input = '<input'.self::attrs($attrs).'>';
        if ($icon !== '') $input = '<div class="relative">'.self::icon($icon, 'pointer-events-none absolute left-3.5 top-1/2 size-4.5 -translate-y-1/2 text-muted').$input.'</div>';
        return '<div class="min-w-0"><label class="label '.self::e($labelClass).'" for="'.self::e($id).'">'.self::e($label).'</label>'.$input.($hint !== '' ? '<p class="hint" id="'.self::e($id).'-hint">'.self::e($hint).'</p>' : '').'</div>';
    }
    public static function select(string $label, string $name, array $options, string $selected = '', array $attrs = []): string
    {
        $id = (string)($attrs['id']??$name);
        $attrs = array_merge(['id'=>$id,'name'=>$name],$attrs);
        $labelClass = (string)($attrs['label_class'] ?? '');
        unset($attrs['label_class']);
        $attrs['class'] = 'field ' . ($attrs['class']??'');
        $html = '<div class="min-w-0"><label class="label '.self::e($labelClass).'" for="'.self::e($id).'">'.self::e($label).'</label><select'.self::attrs($attrs).'>';
        foreach ($options as $value=>$text) $html .= '<option value="'.self::e($value).'"'.((string)$value===$selected?' selected':'').'>'.self::e($text).'</option>';
        return $html . '</select></div>';
    }
    public static function textarea(string $label, string $name, string $value = '', array $attrs = []): string
    {
        $id = (string)($attrs['id']??$name);
        $attrs = array_merge(['id'=>$id,'name'=>$name,'rows'=>3],$attrs);
        $hint = (string)($attrs['hint'] ?? '');
        unset($attrs['hint']);
        if ($hint !== '') $attrs['aria-describedby'] = $id.'-hint';
        $attrs['class'] = 'field min-h-24 resize-y '.($attrs['class']??'');
        return '<div><label class="label" for="'.self::e($id).'">'.self::e($label).'</label><textarea'.self::attrs($attrs).'>'.self::e($value).'</textarea>'.($hint !== '' ? '<p class="hint" id="'.self::e($id).'-hint">'.self::e($hint).'</p>' : '').'</div>';
    }
    public static function table(array $headers, array $rows, array $attrs = []): string
    {
        $attrs['class'] = 'data-table '.($attrs['class']??'');
        $html = '<div class="max-w-full overflow-x-auto"><table'.self::attrs($attrs).'><thead><tr>';
        foreach ($headers as $header) $html .= '<th scope="col">'.self::e($header).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $cells = $row['cells']??$row;
            $rowAttrs = $row['attrs']??['data-search-row'=>true];
            $html .= '<tr'.self::attrs($rowAttrs).'>';
            foreach ($cells as $cell) $html .= '<td>'.$cell.'</td>';
            $html .= '</tr>';
        }
        return $html.'</tbody></table><p data-empty class="hidden p-8 text-center text-muted">No matching records. Try a different search.</p></div>';
    }
    public static function title(string $text, string $icon, string $class = '', string $tag = 'h2'): string
    {
        return '<'.$tag.' class="section-title flex items-center gap-2.5 '.self::e($class).'"><span class="title-icon">'.self::icon($icon, 'size-5').'</span>'.self::e($text).'</'.$tag.'>';
    }
    public static function heading(string $title, string $subtitle = '', string $actions = ''): string
    {
        $icon = self::$pageIcon !== '' ? '<span class="title-icon size-10 md:size-11">'.self::icon(self::$pageIcon, 'size-5 md:size-6').'</span>' : '';
        // Phones: actions sit under the title as full-width buttons; wider screens keep them on the right.
        return '<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex min-w-0 items-center gap-3">'.$icon.'<div class="min-w-0"><h1 class="font-heading text-xl font-bold tracking-tight text-ink md:text-2xl">'.self::e($title).'</h1>'.($subtitle?'<p class="mt-0.5 max-w-2xl text-sm text-muted">'.self::e($subtitle).'</p>':'').'</div></div>'.($actions?'<div class="flex flex-wrap items-center gap-2 empty:hidden max-sm:[&>.btn]:flex-1 sm:shrink-0 sm:gap-3">'.$actions.'</div>':'').'</div>';
    }
    public static function tabs(array $links, string $active): string
    {
        $html = '<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Section tabs">';
        foreach ($links as $href=>$label) $html .= '<a class="tab-link '.($href===$active?'tab-active':'').'" href="'.self::e($href).'"'.($href===$active?' aria-current="page"':'').'>'.self::e($label).'</a>';
        return $html.'</nav>';
    }
    public static function avatar(string $initials, string $class = ''): string
    {
        return '<span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-cyan-100 font-heading text-sm font-bold text-water '.self::e($class).'">'.self::e($initials).'</span>';
    }
    public static function money(float|int $amount): string { return 'PHP ' . number_format($amount,2); }
    public static function productImage(string $class = 'h-20 w-20', string $product = 'round'): string
    {
        $item = Products::get($product) ?? Products::get('round');
        return '<img src="'.self::e($item['image']).'" alt="'.self::e($item['name']).'" class="object-contain '.self::e($class).'" width="256" height="384" loading="lazy">';
    }
}

