import React from 'react';

export default function OqlookMark({ className = '', title = 'OQLook' }) {
  return (
    <svg
      viewBox="0 0 64 64"
      role="img"
      aria-label={title}
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <g fill="none" stroke="currentColor" strokeWidth="4.2" strokeLinejoin="miter" strokeLinecap="butt">
        <path d="M22 8 34 28 22 48 10 28Z" />
        <path d="M38 8 50 28 38 48 26 28Z" />
      </g>
      <g fill="currentColor">
        <path d="M30 20 35 28 30 36 25 28Z" />
      </g>
    </svg>
  );
}
