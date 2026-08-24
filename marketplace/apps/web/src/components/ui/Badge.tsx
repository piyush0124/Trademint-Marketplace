interface BadgeProps {
  variant: 'featured' | 'verified';
  children: React.ReactNode;
}

export default function Badge({ variant, children }: BadgeProps) {
  return <span className={variant === 'featured' ? 'mp-badge-featured' : 'mp-badge-verified'}>{children}</span>;
}
