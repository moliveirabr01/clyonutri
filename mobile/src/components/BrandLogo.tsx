import React from 'react';
import { Image } from 'react-native';

export default function BrandLogo({ light = false, compact = false }: { light?: boolean; compact?: boolean }) {
  return <Image source={light ? require('../../assets/clyonutri-logo-dark.png') : require('../../assets/clyonutri-logo-light.png')}
    accessibilityLabel="Clyo Nutri" resizeMode="contain"
    style={{ width: compact ? 156 : 224, height: compact ? 39 : 56, maxWidth: '100%' }} />;
}
