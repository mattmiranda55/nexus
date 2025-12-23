<script>
  import { createEventDispatcher } from 'svelte';
  import { cva } from 'class-variance-authority';
  import { cn } from '../../../utils';

  export let className = undefined;
  export let variant = 'default';
  export let size = 'default';
  export let type = 'button';
  export let href = undefined;
  export let disabled = false;

  const dispatch = createEventDispatcher();

  function handleClick(event) {
    dispatch('click', event);
  }

  function handleContextmenu(event) {
    const shouldContinue = dispatch('contextmenu', event);
    if (!shouldContinue) {
      event.preventDefault();
    }
  }

  const buttonVariants = cva(
    'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 ring-offset-background',
    {
      variants: {
        variant: {
          default: 'bg-primary text-primary-foreground hover:bg-primary/90',
          secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
          outline: 'border border-input bg-transparent hover:bg-accent hover:text-accent-foreground',
          ghost: 'hover:bg-accent hover:text-accent-foreground',
          destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
        },
        size: {
          default: 'h-10 px-4 py-2',
          sm: 'h-9 rounded-md px-3',
          lg: 'h-11 rounded-md px-8',
          icon: 'h-10 w-10'
        }
      },
      defaultVariants: {
        variant: 'default',
        size: 'default'
      }
    }
  );
</script>

<svelte:element
  this={href ? 'a' : 'button'}
  type={href ? undefined : type}
  disabled={disabled}
  href={href}
  class={cn(buttonVariants({ variant, size }), className, $$props.class)}
  on:click={handleClick}
  on:contextmenu={handleContextmenu}
  {...$$restProps}
>
  <slot />
</svelte:element>
