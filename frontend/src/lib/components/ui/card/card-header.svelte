<script>
  import { cn } from '../../../utils';
  export let className = undefined;
</script>

<div class={cn('flex flex-col space-y-1.5 p-6', className, $$props.class)} {...$$restProps}>
  <slot />
</div>
