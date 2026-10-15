<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { gridClass, spanClass } from '@/lib/records';
import type { RecordPresentedSection } from '@/types';

/**
 * Valeurs d'une fiche du constructeur, par section. Le texte mis en forme
 * est filtré par le serveur (liste blanche) avant d'arriver ici.
 */
withDefaults(defineProps<{ sections: RecordPresentedSection[]; variant?: 'cards' | 'plain' }>(), { variant: 'cards' });

function isInternal(url: string): boolean {
    return url.startsWith('/') || url.startsWith(window.location.origin);
}
</script>

<template>
    <component :is="variant === 'cards' ? 'section' : 'div'" v-for="section in sections" :key="section.key" :class="variant === 'cards' ? 'card' : ''" :aria-label="section.title">
        <header :class="variant === 'cards' ? 'border-b border-slate-200 px-4 py-3' : 'mt-6 mb-2'">
            <h2 :class="variant === 'cards' ? 'text-sm font-semibold text-navy-900' : 'text-xs font-semibold tracking-wider text-slate-500 uppercase'">{{ section.title }}</h2>
            <p v-if="section.description && variant === 'cards'" class="mt-0.5 text-xs whitespace-pre-line text-slate-500">{{ section.description }}</p>
        </header>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm" :class="[gridClass(section), variant === 'cards' ? 'p-4' : '']">
            <div v-for="field in section.fields" :key="field.key" :class="spanClass(field.width, section.columns)">
                <dt class="text-xs font-medium text-slate-500">{{ field.label }}</dt>
                <dd class="mt-0.5 text-slate-800">
                    <!-- eslint-disable-next-line vue/no-v-html -- HTML filtré côté serveur (HtmlSanitizer) -->
                    <div v-if="field.html" class="doc-content" v-html="field.html" />
                    <template v-else-if="field.display === null">—</template>
                    <template v-else-if="field.url">
                        <Link v-if="field.type === 'reference' && isInternal(field.url)" :href="field.url" class="link">{{ field.display }}</Link>
                        <a v-else-if="field.type === 'url'" :href="field.url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1 break-all">
                            {{ field.display }} <ExternalLink class="h-3 w-3" aria-hidden="true" />
                        </a>
                        <a v-else :href="field.url" class="link break-all">{{ field.display }}</a>
                    </template>
                    <span v-else class="whitespace-pre-line" :class="['ip', 'cidr'].includes(field.type) ? 'font-mono' : ''">{{ field.display }}</span>
                </dd>
            </div>
        </dl>
    </component>
</template>
