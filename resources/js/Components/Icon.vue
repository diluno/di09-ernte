<script setup>
// Pixel-art icons (pixelarticons) wrapped behind a small semantic map so usage is
// `<Icon name="search" />` and the icon set lives in one place. Icons inherit the
// surrounding font-size (1em) and currentColor.
import IconSearch from '~icons/pixelarticons/search';
import IconClose from '~icons/pixelarticons/close';
import IconPlay from '~icons/pixelarticons/play';
import IconStar from '~icons/pixelarticons/star';
import IconCheck from '~icons/pixelarticons/check';
import IconChevronUp from '~icons/pixelarticons/chevron-up';
import IconBriefcase from '~icons/pixelarticons/briefcase';
import IconClock from '~icons/pixelarticons/clock';
import IconUsers from '~icons/pixelarticons/users';
import IconReceipt from '~icons/pixelarticons/receipt';
import IconEdit from '~icons/pixelarticons/edit';
import IconChart from '~icons/pixelarticons/chart';
import IconRepeat from '~icons/pixelarticons/repeat';

const MAP = {
  search: IconSearch,
  close: IconClose,
  play: IconPlay,
  star: IconStar,
  check: IconCheck,
  'chevron-up': IconChevronUp,
  briefcase: IconBriefcase,
  clock: IconClock,
  users: IconUsers,
  receipt: IconReceipt,
  edit: IconEdit,
  chart: IconChart,
  repeat: IconRepeat,
};

defineProps({ name: { type: String, required: true } });
</script>

<template>
  <component :is="MAP[name]" class="ico" aria-hidden="true" />
</template>
