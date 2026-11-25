import React from 'react';
import { ui } from '../../theme/commonStyles';
import { colors, radius, spacing } from '../../theme/tokens';

interface PageHeroProps {
  eyebrow?: string;
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
}

const PageHero: React.FC<PageHeroProps> = ({
  eyebrow = 'PORTAL DE PROVEEDORES',
  title,
  subtitle,
  action,
}) => {
  const styles: Record<string, React.CSSProperties> = {
    hero: {
      ...ui.bannerCard,
      borderRadius: radius.xxl,
      padding: '34px',
      marginBottom: spacing.lg,
      background:
        'linear-gradient(135deg, #ffffff 0%, #f8fafc 45%, #eef2ff 100%)',
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: 'flex-start',
      gap: spacing.lg,
      flexWrap: 'wrap',
      border: `1px solid ${colors.border}`,
      boxShadow: '0 18px 45px rgba(15, 23, 42, 0.06)',
    },

    eyebrow: {
      margin: '0 0 18px 0',
      fontSize: '12px',
      fontWeight: 800,
      color: colors.primary,
      textTransform: 'uppercase',
      letterSpacing: '0.14em',
    },

    title: {
      margin: 0,
      fontSize: '38px',
      fontWeight: 900,
      color: colors.textMain,
      letterSpacing: '-0.05em',
      lineHeight: 1.05,
    },

    subtitle: {
      margin: '18px 0 0 0',
      fontSize: '16px',
      color: colors.textSoft,
      lineHeight: 1.6,
      maxWidth: '980px',
    },

    actionWrap: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'flex-end',
      flexShrink: 0,
      paddingTop: '2px',
    },
  };

  return (
    <div style={styles.hero}>
      <div>
        {eyebrow && <p style={styles.eyebrow}>{eyebrow}</p>}

        <h1 style={styles.title}>{title}</h1>

        {subtitle && <p style={styles.subtitle}>{subtitle}</p>}
      </div>

      {action && <div style={styles.actionWrap}>{action}</div>}
    </div>
  );
};

export default PageHero;