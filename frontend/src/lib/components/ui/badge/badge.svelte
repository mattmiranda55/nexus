<script>
  import { cva } from 'class-variance-authority';
  import { cn } from '../../../utils';

  export let className = undefined;
  export let variant = 'secondary';

  const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
    {
      variants: {
        variant: {
          default: 'border-transparent bg-primary text-primary-foreground shadow',
          secondary: 'border-transparent bg-secondary text-secondary-foreground',
          destructive: 'border-transparent bg-destructive text-destructive-foreground shadow',
          outline: 'text-foreground'
        }
      },
      defaultVariants: {
        variant: 'secondary'
      }
    }
  );
</script>

<div class={cn(badgeVariants({ variant }), className, $$props.class)} {...$$restProps}>
  <slot />
</div>
