<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const props = defineProps<{ email: string; token: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout title="Nouveau mot de passe" description="Au moins 12 caractères, avec majuscules, minuscules et chiffres.">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField id="email" label="Adresse e-mail" :error="form.errors.email" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="email" v-model="form.email" type="email" autocomplete="username" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>

            <FormField id="password" label="Nouveau mot de passe" :error="form.errors.password" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="password" v-model="form.password" type="password" autocomplete="new-password" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>

            <FormField id="password_confirmation" label="Confirmation" :error="form.errors.password_confirmation" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                    />
                </template>
            </FormField>

            <button type="submit" class="btn-primary w-full" :disabled="form.processing">Enregistrer le mot de passe</button>
        </form>
    </AuthLayout>
</template>
