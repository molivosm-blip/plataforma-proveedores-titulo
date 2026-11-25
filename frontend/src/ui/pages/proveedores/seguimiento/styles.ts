import React from 'react';

export const styles: Record<string, React.CSSProperties> = {
  page: {
    padding: '24px',
  },
  header: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: '16px',
    marginBottom: '24px',
    flexWrap: 'wrap',
  },
  title: {
    margin: 0,
    fontSize: '28px',
    fontWeight: 700,
    color: '#111827',
  },
  subtitle: {
    marginTop: '6px',
    color: '#6b7280',
  },
  tableCard: {
    background: '#ffffff',
    borderRadius: '16px',
    border: '1px solid #e5e7eb',
    overflow: 'hidden',
    boxShadow: '0 4px 12px rgba(0,0,0,0.04)',
  },
};
