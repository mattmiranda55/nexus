<script>
  import { cn } from '../../../utils';
  export let className = undefined;
</script>

<p class={cn('text-sm text-muted-foreground', className, $$props.class)} {...$$restProps}>
  <slot />
</p>
