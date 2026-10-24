import { useEffect } from 'react';
import { AccessibilityInfo, Platform } from 'react-native';

// Avisa o leitor de tela (VoiceOver/TalkBack) sobre o resultado de uma ação.
// Na web quem avisa é a região viva (role="alert"/aria-live) onde a mensagem aparece na tela.
export function announce(message: string | null | undefined): void {
  if (!message || Platform.OS === 'web') return;
  try { AccessibilityInfo.announceForAccessibility(message); } catch { /* sem leitor de tela */ }
}

// Anuncia a mensagem sempre que ela muda (feedback e erros que aparecem na tela).
export function useAnnounce(message: string | null | undefined): void {
  useEffect(() => { announce(message); }, [message]);
}
