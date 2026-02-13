import React from 'react';
import { cn } from '../../lib/utils';

export function Button({ className, variant = 'default', ...props }) {
  const variants = {
    default: 'oq-btn--default',
    secondary: 'oq-btn--secondary',
    ghost: 'oq-btn--ghost',
    outline: 'oq-btn--outline',
    danger: 'oq-btn--danger',
  };

  return (
    <button
      className={cn(
        'oq-btn inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        variants[variant] ?? variants.default,
        className,
      )}
      {...props}
    />
  );
}
