import React from 'react';
import { colors, radius, shadows, spacing, typography } from './tokens';

export const ui: Record<string, React.CSSProperties> = {
  page: {
    padding: spacing.xl,
    background: colors.bgPage,
    minHeight: '100%',
  },

  card: {
    background: colors.bgCard,
    borderRadius: radius.xxl,
    border: `1px solid ${colors.border}`,
    boxShadow: shadows.card,
    padding: spacing.xl,
  },

  bannerCard: {
    background: 'linear-gradient(135deg, #ffffff 0%, #eef2ff 100%)',
    border: `1px solid ${colors.border}`,
    borderRadius: radius.xxl,
    boxShadow: shadows.card,
    padding: spacing.xl,
  },

  title: {
    margin: 0,
    fontSize: typography.title.fontSize,
    fontWeight: typography.title.fontWeight as React.CSSProperties['fontWeight'],
    letterSpacing: typography.title.letterSpacing,
    color: colors.textMain,
  },

  sectionTitle: {
    margin: '0 0 8px 0',
    fontSize: typography.sectionTitle.fontSize,
    fontWeight: typography.sectionTitle.fontWeight as React.CSSProperties['fontWeight'],
    letterSpacing: typography.sectionTitle.letterSpacing,
    color: colors.textMain,
  },

  subtitle: {
    marginTop: '8px',
    color: colors.textSoft,
    fontSize: typography.subtitle.fontSize,
    lineHeight: typography.subtitle.lineHeight,
  },

  label: {
    fontSize: typography.label.fontSize,
    fontWeight: typography.label.fontWeight as React.CSSProperties['fontWeight'],
    color: '#374151',
  },

  input: {
    width: '100%',
    padding: '13px 14px',
    borderRadius: radius.lg,
    border: `1px solid ${colors.borderStrong}`,
    fontSize: '14px',
    outline: 'none',
    boxSizing: 'border-box',
    background: colors.bgCard,
    color: colors.textMain,
    boxShadow: '0 1px 2px rgba(15, 23, 42, 0.03)',
  },

  primaryButton: {
    background: `linear-gradient(135deg, ${colors.primary} 0%, ${colors.primaryDark} 100%)`,
    color: '#ffffff',
    border: 'none',
    padding: '12px 18px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
    boxShadow: shadows.primary,
  },

  secondaryButton: {
    background: '#f3f4f6',
    color: colors.textMain,
    border: 'none',
    padding: '12px 18px',
    borderRadius: radius.md,
    cursor: 'pointer',
    fontWeight: 700,
  },

  errorBox: {
    background: colors.dangerSoft,
    color: '#991b1b',
    border: '1px solid #fecaca',
    padding: '14px 16px',
    borderRadius: radius.lg,
    fontSize: '14px',
    fontWeight: 600,
  },

  successBox: {
    background: colors.successSoft,
    color: '#166534',
    border: '1px solid #bbf7d0',
    padding: '14px 16px',
    borderRadius: radius.lg,
    fontSize: '14px',
    fontWeight: 600,
  },

  infoCard: {
    background: colors.bgSoft,
    border: `1px solid ${colors.border}`,
    borderRadius: radius.lg,
    padding: '14px',
  },

  badge: {
    padding: '7px 12px',
    borderRadius: radius.pill,
    fontSize: '12px',
    fontWeight: 700,
    display: 'inline-flex',
    alignItems: 'center',
    gap: '6px',
  },
};