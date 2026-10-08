import react from '@vitejs/plugin-react'
import {defineConfig} from 'vite'
export default defineConfig({plugins:[react()], server:{proxy:Object.fromEntries(['/api','/sanctum','/login','/logout','/user','/two-factor-challenge'].map(path=>[path,{target:'http://127.0.0.1:8000',changeOrigin:false}]))}})
