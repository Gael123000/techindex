<script setup lang="ts">
import {
    AppWindow,
    Archive,
    AtSign,
    BatteryCharging,
    Box,
    Camera,
    Cloud,
    Cpu,
    Globe,
    HardDrive,
    Laptop,
    Mail,
    Monitor,
    Network,
    Phone,
    Printer,
    Router,
    Server,
    ServerCog,
    Shield,
    Wifi,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';

const props = defineProps<{ name: string | null | undefined }>();

const icons: Record<string, Component> = {
    'app-window': AppWindow,
    archive: Archive,
    'at-sign': AtSign,
    'battery-charging': BatteryCharging,
    box: Box,
    camera: Camera,
    cloud: Cloud,
    cpu: Cpu,
    globe: Globe,
    'hard-drive': HardDrive,
    laptop: Laptop,
    mail: Mail,
    monitor: Monitor,
    network: Network,
    phone: Phone,
    printer: Printer,
    router: Router,
    server: Server,
    'server-cog': ServerCog,
    shield: Shield,
    wifi: Wifi,
};

const icon = computed(() => icons[props.name ?? ''] ?? Box);
</script>

<template>
    <component :is="icon" aria-hidden="true" />
</template>
