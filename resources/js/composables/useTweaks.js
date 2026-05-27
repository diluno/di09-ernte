import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

let timeout = null;

export function useTweaks() {
  const page = usePage();
  const current = page.props.auth?.user?.settings ?? {};
  const settings = ref({
    theme: current.theme ?? 'paper',
    density: current.density ?? 'comfortable',
    accent: current.accent ?? '#2d4a3a',
  });

  function set(key, value) {
    settings.value[key] = value;
  }

  watch(settings, (val) => {
    if (timeout) clearTimeout(timeout);
    timeout = setTimeout(() => {
      router.patch('/settings/tweaks', val, { preserveScroll: true, preserveState: true });
    }, 500);
  }, { deep: true });

  return { settings, set };
}
