# Resume Sender

A professional bulk email sender application built with PHP and MySQL. Send customized emails to multiple recipients using various SMTP servers.

## Features

- 📧 **Email Campaign Management** - Send personalized emails to multiple recipients
- 🔧 **Multiple SMTP Servers** - Configure and manage multiple SMTP servers
- 👥 **Recipient Management** - Add recipients manually or import via CSV
- 📎 **File Attachments** - Support for multiple file attachments
- 📊 **Email Tracking** - Track sent emails and delivery status
- 🎨 **Professional UI** - Clean, modern interface built with Tailwind CSS

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- PHP Extensions: PDO, OpenSSL (for SMTP)

## Installation

1. **Import the database schema**

```bash
mysql -u resumesender -p resumesender < database_schema.sql
```

Enter password: `RcFfmAFwNMnXyyFe`

2. **Configure your web server**

Point your document root to the `ResumeSender` directory.

3. **Set directory permissions**

Ensure the `uploads` directory is writable:

```bash
chmod -R 755 uploads
```

4. **Access the application**

Open your browser and navigate to:
```
http://localhost/ResumeSender
```

## Usage

### 1. Configure SMTP Server

- Navigate to **Settings** page
- Click **Add SMTP Server**
- Fill in your SMTP credentials
- Save the configuration

### 2. Add Recipients

**Manual Add:**
- Go to **Recipients** page
- Click **Add Recipient**
- Enter email, company name, and position

**CSV Upload:**
- Go to **Recipients** page
- Click **Upload CSV**
- Select CSV file with columns: `email`, `company_name`, `position`

### 3. Send Emails

- Navigate to **Send** page
- Select SMTP server
- Compose email subject and body
- Use placeholders: `{company_name}`, `{position}`, `{email}`
- Attach files (optional)
- Select recipients
- Click **Send Emails**

## CSV Format

Your CSV file should have the following columns:

```csv
email,company_name,position
john@example.com,Acme Inc,Software Engineer
jane@example.com,Tech Corp,Marketing Manager
```

## Database Configuration

- **Database Name:** `resumesender`
- **Username:** `resumesender`
- **Password:** `RcFfmAFwNMnXyyFe`
- **Host:** `localhost`

## File Structure

```
ResumeSender/
├── api/                    # REST API endpoints
├── config.php             # Database and app configuration
├── database_schema.sql    # Database schema
├── index.php             # Entry point
├── layout.php            # Main layout template
├── pages/                # Application pages
│   ├── home.php
│   ├── send.php
│   ├── recipients.php
│   └── settings.php
├── uploads/              # Upload directory
│   ├── attachments/
│   └── csv/
└── utils/                # Utility classes
    ├── email_sender.php
    └── csv_parser.php
```

## Security Notes

- Change default database credentials in production
- The `uploads` directory is protected from direct access
- All file uploads are validated for type and size
- SQL injection protection via PDO prepared statements

## Support

For issues or questions, please check the documentation or contact support.

## License

Proprietary - All rights reserved by [Khoo Lay Yang](https://www.dkly.net)

