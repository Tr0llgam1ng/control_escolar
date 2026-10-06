import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { ConfigService } from '@nestjs/config';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  const config = app.get(ConfigService);

  app.setGlobalPrefix('api');
  app.enableCors({
    origin: (config.get<string>('CORS_ORIGIN') ?? 'http://localhost:5173').split(','),
    credentials: false,
  });

  const port = Number(config.get('PORT') ?? 3000);
  await app.listen(port);
  console.log(`API Gateway listening on http://localhost:${port}/api`);
}

bootstrap();
