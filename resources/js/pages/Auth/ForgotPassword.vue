<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{ status?: string | null }>();

const form = useForm({ email: '' });

function submit(): void {
    form.post(route('password.email'));
}
</script>

<template>
    <AuthLayout title="Mot de passe oublié" description="Indiquez votre adresse e-mail : si un compte actif y correspond, vous recevrez un lien de réinitialisation.">
        <p v-if="status" class="mb-4 rounded-md bg-turquoise-50 px-3 py-2 text-sm text-turquoise-800" role="status">{{ status }}</p>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField id="email" label="Adresse e-mail" :error="form.errors.email" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="email" v-model="form.email" type="email" autocomplete="username" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>

            <button type="submit" class="btn-primary w-full" :disabled="form.processing">Envoyer le lien</button>
        </form>

        <p class="mt-5 text-center text-sm">
            <Link :href="route('login')" class="link">Retour à la connexion</Link>
        </p>
    </AuthLayout>
</template>
