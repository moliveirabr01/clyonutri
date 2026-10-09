import React, { useEffect, useState } from 'react';
import { ActivityIndicator, SafeAreaView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import PatientHomeScreen from './src/screens/PatientHomeScreen';
import ProfessionalHomeScreen from './src/screens/ProfessionalHomeScreen';
import { ApiService, PatientAuthData, ProfessionalAuthData, setSessionExpiredHandler } from './src/services/api';
import { tokens as t } from './src/theme/tokens';

export default function App() {
  const [session, setSession] = useState<PatientAuthData | null>(null);
  const [profSession, setProfSession] = useState<ProfessionalAuthData | null>(null);
  const [role, setRole] = useState<'patient' | 'professional'>('patient');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    setSessionExpiredHandler(() => {
      setSession(null);
      setProfSession(null);
      setPassword('');
      setMessage('Sua sessão expirou ou o acesso foi revogado. Entre novamente.');
    });
    return () => setSessionExpiredHandler(null);
  }, []);

  async function login() {
    if (busy) return;
    if (!email.trim() || !password) {
      setMessage('Informe seu e-mail e sua senha.');
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      if (role === 'patient') {
        const auth = await ApiService.login({ email: email.trim(), password });
        setPassword('');
        setSession(auth);
      } else {
        const profAuth = await ApiService.loginProfessional({ email: email.trim(), password });
        setPassword('');
        setProfSession(profAuth);
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Não foi possível entrar. Tente novamente.');
    } finally {
      setBusy(false);
    }
  }

  if (session) {
    return (
      <PatientHomeScreen
        session={session}
        onLogout={() => {
          setSession(null);
          setMessage('Sessão de paciente encerrada.');
        }}
      />
    );
  }

  if (profSession) {
    return (
      <ProfessionalHomeScreen
        session={profSession}
        onLogout={() => {
          setProfSession(null);
          setMessage('Sessão profissional encerrada.');
        }}
      />
    );
  }

  return (
    <SafeAreaView style={styles.page}>
      <View style={styles.card}>
        <Text style={styles.brand}>ClyoNutri</Text>
        <Text accessibilityRole="header" style={styles.title}>
          {role === 'patient' ? 'Acompanhamento do Paciente' : 'Acesso do Nutricionista'}
        </Text>
        <Text style={styles.description}>
          {role === 'patient'
            ? 'Entre com o e-mail cadastrado e sua senha de paciente.'
            : 'Entre com sua credencial de nutricionista para acessar seu consultório.'}
        </Text>

        {/* Seletor de Papel / Jornada (NI2-292) */}
        <View style={styles.roleSelector} accessibilityRole="tablist">
          <TouchableOpacity
            style={[styles.roleTab, role === 'patient' && styles.roleTabActive]}
            onPress={() => {
              setRole('patient');
              setMessage(null);
            }}
            accessibilityRole="tab"
            accessibilityState={{ selected: role === 'patient' }}
            accessibilityLabel="Entrar como Paciente"
          >
            <Text style={[styles.roleTabText, role === 'patient' && styles.roleTabTextActive]}>
              Paciente
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.roleTab, role === 'professional' && styles.roleTabActive]}
            onPress={() => {
              setRole('professional');
              setMessage(null);
            }}
            accessibilityRole="tab"
            accessibilityState={{ selected: role === 'professional' }}
            accessibilityLabel="Entrar como Nutricionista"
          >
            <Text style={[styles.roleTabText, role === 'professional' && styles.roleTabTextActive]}>
              Nutricionista
            </Text>
          </TouchableOpacity>
        </View>

        <Text style={styles.label}>E-mail</Text>
        <TextInput
          accessibilityLabel={role === 'patient' ? 'E-mail do paciente' : 'E-mail do profissional'}
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          autoComplete="email"
          editable={!busy}
        />

        <Text style={styles.label}>Senha</Text>
        <TextInput
          accessibilityLabel={role === 'patient' ? 'Senha do paciente' : 'Senha do profissional'}
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="current-password"
          editable={!busy}
          onSubmitEditing={login}
        />

        {message && <Text accessibilityRole="alert" style={styles.message}>{message}</Text>}

        <TouchableOpacity
          accessibilityRole="button"
          accessibilityLabel="Entrar"
          disabled={busy}
          accessibilityState={{ disabled: busy, busy }}
          onPress={login}
          style={styles.button}
        >
          {busy ? (
            <ActivityIndicator color={t.white} accessibilityLabel="Autenticando" />
          ) : (
            <Text style={styles.buttonText}>Entrar</Text>
          )}
        </TouchableOpacity>

        <Text style={styles.footerNote}>
          {role === 'patient'
            ? 'Primeiro acesso ou senha esquecida: contate seu nutricionista para emissão da credencial.'
            : 'Acesso restrito a profissionais de saúde e nutricionistas cadastrados na clínica.'}
        </Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: t.background, alignItems: 'center', justifyContent: 'center', padding: 24 },
  card: { width: '100%', maxWidth: 440, backgroundColor: t.white, padding: 24, borderRadius: t.cardRadius, gap: t.space },
  brand: { color: t.navy, fontSize: 28, fontWeight: '700' },
  title: { color: t.ink, fontSize: 22, fontWeight: '600' },
  description: { color: t.muted, fontSize: 14, lineHeight: 22 },
  roleSelector: { flexDirection: 'row', backgroundColor: t.background, borderRadius: t.inputRadius, padding: 4, gap: 4 },
  roleTab: { flex: 1, paddingVertical: 10, alignItems: 'center', borderRadius: 6, minHeight: 44, justifyContent: 'center' },
  roleTabActive: { backgroundColor: t.navy },
  roleTabText: { color: t.muted, fontSize: 14, fontWeight: '600' },
  roleTabTextActive: { color: t.white, fontWeight: '700' },
  label: { color: t.ink, fontSize: 16 },
  input: { borderWidth: 1, borderColor: t.border, borderRadius: t.inputRadius, padding: 12, fontSize: 16, color: t.ink, minHeight: 48 },
  message: { color: t.error, fontSize: 14, lineHeight: 22 },
  button: { backgroundColor: t.copper, padding: 14, borderRadius: t.buttonRadius, alignItems: 'center', minHeight: 48, justifyContent: 'center' },
  buttonText: { color: t.white, fontSize: 16, fontWeight: '600' },
  footerNote: { color: t.muted, fontSize: 12, lineHeight: 18, textAlign: 'center' },
});
