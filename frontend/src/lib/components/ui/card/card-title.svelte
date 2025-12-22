<script>
  import { cn } from '../../../utils';
  export let className = undefined;
</script>

<h3 class={cn('text-lg font-semibold leading-none tracking-tight', className, $$props.class)} {...$$restProps}>
  <slot />
</h3>
