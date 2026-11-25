export const ZapIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="zapGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#f093fb" />
        <stop offset="100%" stopColor="#f5576c" />
      </linearGradient>
    </defs>
    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" 
             fill="url(#zapGradient)" stroke="url(#zapGradient)" strokeWidth="1"/>
  </svg>
);

export const XIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="xGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#667eea" />
        <stop offset="100%" stopColor="#764ba2" />
      </linearGradient>
    </defs>
    <line x1="18" y1="6" x2="6" y2="18" stroke="url(#xGradient)" strokeWidth="2" strokeLinecap="round"/>
    <line x1="6" y1="6" x2="18" y2="18" stroke="url(#xGradient)" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

export const ChevronDownIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="chevronDownGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#4facfe" />
        <stop offset="100%" stopColor="#00f2fe" />
      </linearGradient>
    </defs>
    <polyline points="6 9 12 15 18 9" stroke="url(#chevronDownGradient)" 
              strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
  </svg>
);

export const ChevronRightIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="chevronRightGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#4facfe" />
        <stop offset="100%" stopColor="#00f2fe" />
      </linearGradient>
    </defs>
    <polyline points="9 18 15 12 9 6" stroke="url(#chevronRightGradient)" 
              strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
  </svg>
);

export const UserIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="userGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#667eea" />
        <stop offset="100%" stopColor="#764ba2" />
      </linearGradient>
    </defs>
    <circle cx="12" cy="7" r="4" fill="url(#userGradient)"/>
    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" 
          stroke="url(#userGradient)" strokeWidth="2" fill="none"/>
  </svg>
);

export const SettingsIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="settingsIconGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#a8edea" />
        <stop offset="100%" stopColor="#fed6e3" />
      </linearGradient>
    </defs>
    <circle cx="12" cy="12" r="3" fill="url(#settingsIconGradient)"/>
    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1 1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" 
          stroke="url(#settingsIconGradient)" strokeWidth="1.5" fill="none"/>
  </svg>
);

export const LogOutIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
    <defs>
      <linearGradient id="logoutGradient" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stopColor="#f093fb" />
        <stop offset="100%" stopColor="#f5576c" />
      </linearGradient>
    </defs>
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" 
          stroke="url(#logoutGradient)" strokeWidth="2" fill="none" strokeLinecap="round"/>
    <polyline points="16 17 21 12 16 7" 
              stroke="url(#logoutGradient)" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
    <line x1="21" y1="12" x2="9" y2="12" 
          stroke="url(#logoutGradient)" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);