<script>
  import { cn } from '../../../utils';
  export let className = undefined;
</script>

<div class={cn('p-6 pt-0', className, $$props.class)} {...$$restProps}>
  <slot />
</div>
