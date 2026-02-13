import React from 'react';
import { cn } from '../../lib/utils';

export function Card({ className, children }) {
  return <div className={cn('oq-card p-5', className)}>{children}</div>;
}

export function CardTitle({ className, children }) {
  return <h3 className={cn('text-lg font-bold tracking-tight', className)}>{children}</h3>;
}

export function CardDescription({ className, children }) {
  return <p className={cn('mt-1 text-sm text-slate-500', className)}>{children}</p>;
}
