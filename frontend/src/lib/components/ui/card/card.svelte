<script>
  import { cn } from '../../../utils';
  export let className = undefined;
</script>

<div class={cn('rounded-lg border bg-card text-card-foreground shadow-sm', className, $$props.class)} {...$$restProps}>
  <slot />
</div>
