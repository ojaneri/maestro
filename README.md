# Maestro – WhatsApp Orchestrator

Maestro is a multi-instance WhatsApp management system that allows you to orchestrate multiple WhatsApp Business API instances through a modern, responsive web interface. It provides an easy way to send messages, manage QR codes for authentication, and monitor instance statuses.

## Features

- **Multi-Instance Management**: Create and manage multiple WhatsApp instances
- **QR Code Authentication**: Generate and display QR codes for WhatsApp Web authentication
- **Message Sending**: Send text messages to WhatsApp numbers
- **Real-time Status Monitoring**: Check connection and server status for each instance
- **Modern UI**: Responsive design built with Tailwind CSS
- **Authentication**: Secure login system
- **Instance Configuration**: Customize instance names, providers, and settings

## Technologies Used

- **Backend**: PHP 7.4+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **WhatsApp Integration**: Baileys, Evolution API, Custom providers
- **Database**: JSON file storage (instances.json)
- **Web Server**: Built-in PHP server or Apache/Nginx
- **QR Code Generation**: External API (qrserver.com)
- **Dependencies**: Composer for PHP packages

## Installation

1. Clone the repository:
```bash
git clone https://github.com/ojaneri/maestro.git
cd maestro
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install Node.js dependencies (if using Node.js instances):
```bash
npm install
```

4. Configure environment variables in `.env`:
```env
PANEL_USER_EMAIL=your-email@example.com
PANEL_PASSWORD=your-secure-password
```

5. Start the web server:
```bash
php -S localhost:8000 index.php
```

## Usage

1. Access the web interface at `http://localhost:8000`
2. Log in with your configured credentials
3. Create a new WhatsApp instance
4. Connect via QR code in the modal
5. Send test messages or integrate with your applications

## API Endpoints

- `GET /health` - Health check
- `GET /status` - Instance status
- `POST /send` - Send message
- `GET /qr` - Get QR code for authentication

## Project Structure

```
maestro/
├── index.php              # Main application file
├── instances.json         # Instance configurations
├── composer.json          # PHP dependencies
├── package.json           # Node.js dependencies
├── styles.css             # Custom styles
├── scripts.js             # Frontend JavaScript
├── create_instance.sh     # Instance creation script
├── stop_instance.sh       # Instance stop script
├── qr-proxy.php           # QR code proxy
├── ws-proxy.php           # WebSocket proxy
└── README.md              # This file
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Credits

Developed by **Osvaldo J. Filho**
- Website: https://perito.digital
- LinkedIn: https://linkedin.com/in/ojaneri

---

# Maestro – Orquestrador WhatsApp

Maestro é um sistema de gerenciamento multi-instância do WhatsApp que permite orquestrar múltiplas instâncias da API do WhatsApp Business através de uma interface web moderna e responsiva. Ele fornece uma maneira fácil de enviar mensagens, gerenciar códigos QR para autenticação e monitorar status de instâncias.

## Funcionalidades

- **Gerenciamento Multi-Instância**: Criar e gerenciar múltiplas instâncias do WhatsApp
- **Autenticação por Código QR**: Gerar e exibir códigos QR para autenticação do WhatsApp Web
- **Envio de Mensagens**: Enviar mensagens de texto para números do WhatsApp
- **Monitoramento de Status em Tempo Real**: Verificar status de conexão e servidor para cada instância
- **Interface Moderna**: Design responsivo construído com Tailwind CSS
- **Autenticação**: Sistema de login seguro
- **Configuração de Instâncias**: Personalizar nomes de instâncias, provedores e configurações

## Tecnologias Utilizadas

- **Backend**: PHP 7.4+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **Integração WhatsApp**: Baileys, Evolution API, Provedores customizados
- **Banco de Dados**: Armazenamento em arquivo JSON (instances.json)
- **Servidor Web**: Servidor PHP integrado ou Apache/Nginx
- **Geração de Código QR**: API externa (qrserver.com)
- **Dependências**: Composer para pacotes PHP

## Instalação

1. Clone o repositório:
```bash
git clone https://github.com/ojaneri/maestro.git
cd maestro
```

2. Instale as dependências PHP:
```bash
composer install
```

3. Instale as dependências Node.js (se usar instâncias Node.js):
```bash
npm install
```

4. Configure as variáveis de ambiente no `.env`:
```env
PANEL_USER_EMAIL=seu-email@exemplo.com
PANEL_PASSWORD=sua-senha-segura
```

5. Inicie o servidor web:
```bash
php -S localhost:8000 index.php
```

## Uso

1. Acesse a interface web em `http://localhost:8000`
2. Faça login com suas credenciais configuradas
3. Crie uma nova instância do WhatsApp
4. Conecte via código QR no modal
5. Envie mensagens de teste ou integre com suas aplicações

## Endpoints da API

- `GET /health` - Verificação de saúde
- `GET /status` - Status da instância
- `POST /send` - Enviar mensagem
- `GET /qr` - Obter código QR para autenticação

## Estrutura do Projeto

```
maestro/
├── index.php              # Arquivo principal da aplicação
├── instances.json         # Configurações de instâncias
├── composer.json          # Dependências PHP
├── package.json           # Dependências Node.js
├── styles.css             # Estilos customizados
├── scripts.js             # JavaScript do frontend
├── create_instance.sh     # Script de criação de instância
├── stop_instance.sh       # Script de parada de instância
├── qr-proxy.php           # Proxy de código QR
├── ws-proxy.php           # Proxy WebSocket
└── README.md              # Este arquivo
```

## Contribuição

Contribuições são bem-vindas! Sinta-se à vontade para enviar um Pull Request.

## Licença

Este projeto está licenciado sob a Licença MIT - veja o arquivo LICENSE para detalhes.

## Créditos

Desenvolvido por **Osvaldo J. Filho**
- Website: https://perito.digital
- LinkedIn: https://linkedin.com/in/ojaneri
