<script setup lang="ts">
interface Props {
  variant?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'light' | 'dark';
  dismissible?: boolean;
  show?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  dismissible: false,
  show: true,
});

const emit = defineEmits<{
  close: [];
}>();

const handleClose = () => {
  emit('close');
};

const getAlertClass = () => {
  const baseClass = 'alert';
  const variantClass = `alert-${props.variant}`;
  const dismissibleClass = props.dismissible ? 'alert-dismissible fade show' : '';
  
  return [baseClass, variantClass, dismissibleClass].filter(Boolean).join(' ');
};

const getIconClass = () => {
  const iconMap: Record<string, string> = {
    primary: 'bi-info-circle',
    secondary: 'bi-info-circle',
    success: 'bi-check-circle',
    danger: 'bi-exclamation-triangle',
    warning: 'bi-exclamation-triangle',
    info: 'bi-info-circle',
    light: 'bi-info-circle',
    dark: 'bi-info-circle',
  };
  
  return iconMap[props.variant] || 'bi-info-circle';
};
</script>

<template>
  <div
    v-if="show"
    :class="getAlertClass()"
    role="alert"
  >
    <i :class="getIconClass()" class="me-2"></i>
    <slot />
    
    <button
      v-if="dismissible"
      type="button"
      class="btn-close"
      @click="handleClose"
      aria-label="Fermer"
    ></button>
  </div>
</template>