<script setup lang="ts">
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import {
    Bold,
    Code,
    Heading2,
    Heading3,
    Italic,
    Link2,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    SquareCode,
    Strikethrough,
    Table,
    Underline,
    Undo2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { onBeforeUnmount, watch } from 'vue';

/**
 * Éditeur de contenu riche (TipTap). Le HTML produit est de toute façon
 * nettoyé côté serveur avant enregistrement et à l'affichage.
 */
const model = defineModel<string>({ required: true });
const props = defineProps<{ id: string; describedBy?: string; invalid?: boolean }>();

const editor = useEditor({
    content: model.value,
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            link: { openOnClick: false, autolink: true, protocols: ['http', 'https', 'mailto'], HTMLAttributes: { rel: 'noopener noreferrer nofollow' } },
        }),
        TableKit.configure({ table: { resizable: false } }),
        Placeholder.configure({ placeholder: 'Rédigez la procédure, le guide ou la consigne…' }),
    ],
    editorProps: {
        attributes: {
            id: props.id,
            class: 'doc-content',
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': 'Contenu de la fiche',
            ...(props.describedBy ? { 'aria-describedby': props.describedBy } : {}),
        },
    },
    onUpdate: ({ editor: instance }) => {
        model.value = instance.getHTML();
    },
});

watch(model, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value, { emitUpdate: false });
    }
});

onBeforeUnmount(() => editor.value?.destroy());

function setLink(): void {
    if (!editor.value) {
        return;
    }

    const previous = editor.value.getAttributes('link').href as string | undefined;
    const url = window.prompt('Adresse du lien (laisser vide pour retirer le lien)', previous ?? 'https://');

    if (url === null) {
        return;
    }

    if (url.trim() === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();

        return;
    }

    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
}

interface ToolbarButton {
    label: string;
    icon: Component;
    action: () => void;
    active?: () => boolean;
}

const buttons: ToolbarButton[][] = [
    [
        { label: 'Annuler', icon: Undo2, action: () => editor.value?.chain().focus().undo().run() },
        { label: 'Rétablir', icon: Redo2, action: () => editor.value?.chain().focus().redo().run() },
    ],
    [
        { label: 'Titre de section', icon: Heading2, action: () => editor.value?.chain().focus().toggleHeading({ level: 2 }).run(), active: () => !!editor.value?.isActive('heading', { level: 2 }) },
        { label: 'Sous-titre', icon: Heading3, action: () => editor.value?.chain().focus().toggleHeading({ level: 3 }).run(), active: () => !!editor.value?.isActive('heading', { level: 3 }) },
    ],
    [
        { label: 'Gras', icon: Bold, action: () => editor.value?.chain().focus().toggleBold().run(), active: () => !!editor.value?.isActive('bold') },
        { label: 'Italique', icon: Italic, action: () => editor.value?.chain().focus().toggleItalic().run(), active: () => !!editor.value?.isActive('italic') },
        { label: 'Souligné', icon: Underline, action: () => editor.value?.chain().focus().toggleUnderline().run(), active: () => !!editor.value?.isActive('underline') },
        { label: 'Barré', icon: Strikethrough, action: () => editor.value?.chain().focus().toggleStrike().run(), active: () => !!editor.value?.isActive('strike') },
        { label: 'Code', icon: Code, action: () => editor.value?.chain().focus().toggleCode().run(), active: () => !!editor.value?.isActive('code') },
        { label: 'Lien', icon: Link2, action: setLink, active: () => !!editor.value?.isActive('link') },
    ],
    [
        { label: 'Liste à puces', icon: List, action: () => editor.value?.chain().focus().toggleBulletList().run(), active: () => !!editor.value?.isActive('bulletList') },
        { label: 'Liste numérotée', icon: ListOrdered, action: () => editor.value?.chain().focus().toggleOrderedList().run(), active: () => !!editor.value?.isActive('orderedList') },
        { label: 'Citation / avertissement', icon: Quote, action: () => editor.value?.chain().focus().toggleBlockquote().run(), active: () => !!editor.value?.isActive('blockquote') },
        { label: 'Bloc de commandes', icon: SquareCode, action: () => editor.value?.chain().focus().toggleCodeBlock().run(), active: () => !!editor.value?.isActive('codeBlock') },
        { label: 'Séparateur', icon: Minus, action: () => editor.value?.chain().focus().setHorizontalRule().run() },
        { label: 'Insérer un tableau', icon: Table, action: () => editor.value?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() },
    ],
];

const tableActions = [
    { label: '+ ligne', action: () => editor.value?.chain().focus().addRowAfter().run() },
    { label: '+ colonne', action: () => editor.value?.chain().focus().addColumnAfter().run() },
    { label: '− ligne', action: () => editor.value?.chain().focus().deleteRow().run() },
    { label: '− colonne', action: () => editor.value?.chain().focus().deleteColumn().run() },
    { label: 'Supprimer le tableau', action: () => editor.value?.chain().focus().deleteTable().run() },
];
</script>

<template>
    <div class="overflow-hidden rounded-field border bg-white" :class="invalid ? 'border-red-500' : 'border-slate-300 focus-within:border-turquoise-600'">
        <div role="toolbar" aria-label="Mise en forme" class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 px-2 py-1.5">
            <template v-for="(group, index) in buttons" :key="index">
                <span v-if="index > 0" class="mx-1 h-5 w-px bg-slate-300" aria-hidden="true" />
                <button
                    v-for="button in group"
                    :key="button.label"
                    type="button"
                    class="rounded p-1.5 text-slate-600 hover:bg-slate-200 hover:text-navy-900"
                    :class="button.active?.() ? 'bg-navy-100 text-navy-900' : ''"
                    :aria-pressed="button.active ? button.active() : undefined"
                    :title="button.label"
                    @click="button.action"
                >
                    <component :is="button.icon" class="h-4 w-4" aria-hidden="true" />
                    <span class="sr-only">{{ button.label }}</span>
                </button>
            </template>
            <template v-if="editor?.isActive('table')">
                <span class="mx-1 h-5 w-px bg-slate-300" aria-hidden="true" />
                <button v-for="item in tableActions" :key="item.label" type="button" class="rounded px-2 py-1 text-xs text-slate-700 hover:bg-slate-200" @click="item.action">
                    {{ item.label }}
                </button>
            </template>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>
