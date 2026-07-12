@props(['field' => null, 'live' => false, 'class' => ''])
<div x-data="{
    raw: '',
    field: '{{ $field }}',
    init() {
        let iso = $wire.get(this.field) || '';
        this.raw = iso ? this.fmt(iso) : '';
    },
    fmt(iso) {
        let p = iso.split('-');
        return p.length === 3 ? p[2]+'/'+p[1]+'/'+p[0] : '';
    },
    toIso() {
        let p = this.raw.split('/');
        if (p.length !== 3 || p[2].length !== 4) return '';
        return p[2]+'-'+p[1].padStart(2,'0')+'-'+p[0].padStart(2,'0');
    },
    mask() {
        let v = this.raw.replace(/[^0-9]/g, '').slice(0, 8);
        let r = [];
        if (v.length > 0) r.push(v.slice(0, 2));
        if (v.length > 2) r.push(v.slice(2, 4));
        if (v.length > 4) r.push(v.slice(4));
        this.raw = r.join('/');
    },
    push() {
        $wire.set(this.field, this.toIso());
    }
}" x-init="init()" class="relative">
    <input type="text" x-model="raw" @input="mask()" @change="push()" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10"
           {{ $attributes->merge(['class' => 'w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500 pr-9 ' . $class]) }}>
    <button type="button" @click="
        let dp = document.createElement('input');
        dp.type = 'date';
        dp.style.cssText = 'position:absolute;opacity:0;width:0;height:0;top:0;left:0;pointer-events:none';
        dp.value = toIso();
        $el.parentElement.appendChild(dp);
        dp.showPicker();
        dp.addEventListener('change', () => { raw = fmt(dp.value); push(); dp.remove(); });
        dp.addEventListener('blur', () => setTimeout(() => dp.remove(), 300));
    " class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink/30 hover:text-gold-600">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
    </button>
</div>
