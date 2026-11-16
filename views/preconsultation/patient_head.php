<?php /** Cabeçalho comum das páginas do paciente. Variáveis: $e, $pageTitle, $clinicTitle */ ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $e($pageTitle) ?></title>
  <link rel="stylesheet" href="/assets/css/pre-consulta.css?v=botanical-v54-9">
  <link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
</head>
<body>
<a class="pc-skip" href="#pc-conteudo">Pular para o conteúdo</a>
<header class="pc-header"><p class="pc-brand"><?= $e($clinicTitle !== '' ? $clinicTitle : 'Clyo Nutri') ?></p></header>
<main id="pc-conteudo" class="pc-main">
