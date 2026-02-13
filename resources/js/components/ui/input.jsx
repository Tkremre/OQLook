import React from 'react';
import { cn } from '../../lib/utils';

export function Input({ className, ...props }) {
  return (
    <input
      className={cn(
        'oq-input w-full rounded-xl px-3 py-2 text-sm outline-none transition',
        className,
      )}
      {...props}
    />
  );
}

export function Select({ className, children, ...props }) {
  return (
    <select
      className={cn(
        'oq-input w-full rounded-xl px-3 py-2 text-sm outline-none transition',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
}

export function Textarea({ className, ...props }) {
  return (
    <textarea
      className={cn(
        'oq-input w-full rounded-xl px-3 py-2 text-sm outline-none transition',
        className,
      )}
      {...props}
    />
  );
}
