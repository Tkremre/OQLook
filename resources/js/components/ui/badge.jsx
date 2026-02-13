import React from 'react';
import { cn } from '../../lib/utils';

export function Badge({ className, tone = 'slate', children }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700',
    good: 'bg-emerald-100 text-emerald-700',
    warn: 'bg-amber-100 text-amber-700',
    crit: 'bg-rose-100 text-rose-700',
    info: 'bg-sky-100 text-sky-700',
  };

  return (
    <span className={cn('inline-flex rounded-full px-2.5 py-1 text-xs font-semibold uppercase', tones[tone] ?? tones.slate, className)}>
      {children}
    </span>
  );
}
