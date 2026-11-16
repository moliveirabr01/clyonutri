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

// React Native Web activates radio Touchables with Enter, but does not implement
// Space or arrow navigation. Keep native touch behavior and complete web keyboard semantics.
export function radioKeyboardProps(onSelect: () => void, disabled = false): object {
  if (Platform.OS !== 'web') return {};
  return {
    onKeyDownCapture: (event: any) => {
      if (disabled) return;
      if (event.key === ' ') {
        event.preventDefault();
        onSelect();
      } else if (['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown'].includes(event.key)) {
        const group = event.currentTarget.closest('[role="radiogroup"]');
        const options: HTMLElement[] = Array.from(group?.querySelectorAll('[role="radio"]:not([aria-disabled="true"])') ?? []);
        const index = options.indexOf(event.currentTarget);
        if (index < 0 || !options.length) return;
        event.preventDefault();
        const offset = ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1;
        const next = options[(index + offset + options.length) % options.length];
        next.focus();
        next.click();
      }
    },
  };
}
