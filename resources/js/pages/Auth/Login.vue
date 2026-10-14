<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{ status?: string | null }>();

const form = useForm({
    email: '',
    password: '',
});

function submit(): void {
    form.post(route('login.store'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout title="Connexion" description="Identifiez-vous avec votre compte TechIndex.">
        <p v-if="status" class="mb-4 rounded-md bg-turquoise-50 px-3 py-2 text-sm text-turquoise-800" role="status">{{ status }}</p>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField id="email" label="Adresse e-mail" :error="form.errors.email" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                        autofocus
                    />
                </template>
            </FormField>

            <FormField id="password" label="Mot de passe" :error="form.errors.password" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                    />
                </template>
            </FormField>

            <button type="submit" class="btn-primary w-full" :disabled="form.processing">Se connecter</button>
        </form>

        <p class="mt-5 text-center text-sm">
            <Link :href="route('password.request')" class="link">Mot de passe oublié ?</Link>
        </p>
    </AuthLayout>
</template>
