<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const form = useForm({ password: '' });

function submit(): void {
    form.post(route('password.confirm.store'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <AuthLayout title="Confirmez votre mot de passe" description="Cette opération est sensible : confirmez votre mot de passe pour continuer.">
        <form class="space-y-4" @submit.prevent="submit">
            <FormField id="password" label="Mot de passe" :error="form.errors.password" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="password" v-model="form.password" type="password" autocomplete="current-password" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <button type="submit" class="btn-primary w-full" :disabled="form.processing">Confirmer</button>
        </form>
    </AuthLayout>
</template>
