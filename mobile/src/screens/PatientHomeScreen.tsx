import React, { useState } from 'react';
import { StyleSheet, Text, View, ScrollView, TouchableOpacity, SafeAreaView } from 'react-native';

export default function PatientHomeScreen() {
  const [waterMl, setWaterMl] = useState(1250);

  const addWater = (ml: number) => {
    setWaterMl(prev => prev + ml);
  };

  return (
    <SafeAreaView style={styles.container}>
      <ScrollView contentContainerStyle={styles.scroll}>
        {/* Header */}
        <View style={styles.header}>
          <Text style={styles.greeting}>Olá, Roberto 👋</Text>
          <Text style={styles.subGreeting}>Aqui está o seu plano alimentar para hoje</Text>
        </View>

        {/* Card de Hidratação */}
        <View style={styles.card}>
          <Text style={styles.cardTitle}>💧 Hidratação Diária</Text>
          <Text style={styles.waterValue}>{waterMl} ml / 2.500 ml</Text>
          <View style={styles.buttonRow}>
            <TouchableOpacity style={styles.waterButton} onPress={() => addWater(250)}>
              <Text style={styles.buttonText}>+ 250 ml</Text>
            </TouchableOpacity>
            <TouchableOpacity style={styles.waterButton} onPress={() => addWater(500)}>
              <Text style={styles.buttonText}>+ 500 ml</Text>
            </TouchableOpacity>
          </View>
        </View>

        {/* Refeições do Dia */}
        <Text style={styles.sectionTitle}>Refeições de Hoje</Text>

        <View style={styles.mealCard}>
          <View style={styles.mealHeader}>
            <Text style={styles.mealName}>Almoço</Text>
            <Text style={styles.mealTime}>12:30</Text>
          </View>
          <Text style={styles.mealItem}>• 150g de Arroz branco cozido</Text>
          <Text style={styles.mealItem}>• 150g de Peito de frango grelhado</Text>
          <Text style={styles.mealItem}>• Salada de folhas verdes à vontade</Text>
          <TouchableOpacity style={styles.subButton}>
            <Text style={styles.subButtonText}>Ver substituições permitidas</Text>
          </TouchableOpacity>
        </View>

        <View style={styles.mealCard}>
          <View style={styles.mealHeader}>
            <Text style={styles.mealName}>Lanche da Tarde</Text>
            <Text style={styles.mealTime}>16:00</Text>
          </View>
          <Text style={styles.mealItem}>• 1 unidade de Banana prata</Text>
          <Text style={styles.mealItem}>• 30g de Aveia em flocos</Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#F4F5F6',
  },
  scroll: {
    padding: 20,
  },
  header: {
    marginBottom: 20,
  },
  greeting: {
    fontSize: 24,
    fontWeight: '700',
    color: '#0F2747',
  },
  subGreeting: {
    fontSize: 14,
    color: '#667085',
    marginTop: 4,
  },
  card: {
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    padding: 16,
    marginBottom: 20,
    borderWidth: 1,
    borderColor: '#E5E7EB',
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '600',
    color: '#0F2747',
    marginBottom: 8,
  },
  waterValue: {
    fontSize: 22,
    fontWeight: '700',
    color: '#175CD3',
    marginBottom: 12,
  },
  buttonRow: {
    flexDirection: 'row',
    gap: 10,
  },
  waterButton: {
    backgroundColor: '#0F2747',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 8,
  },
  buttonText: {
    color: '#FFFFFF',
    fontWeight: '600',
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '600',
    color: '#0F2747',
    marginBottom: 12,
  },
  mealCard: {
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#E5E7EB',
  },
  mealHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  mealName: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0F2747',
  },
  mealTime: {
    fontSize: 14,
    fontWeight: '600',
    color: '#E67A3F',
  },
  mealItem: {
    fontSize: 14,
    color: '#1F2937',
    marginBottom: 4,
  },
  subButton: {
    marginTop: 8,
    paddingVertical: 6,
  },
  subButtonText: {
    fontSize: 13,
    color: '#B94F1F',
    fontWeight: '600',
  },
});
