import {defineConfig} from '@playwright/test'
export default defineConfig({testDir:'./e2e',fullyParallel:false,workers:1,timeout:90000,use:{baseURL:process.env.PLAYWRIGHT_BASE_URL??'http://localhost:8080',trace:'retain-on-failure'},reporter:'list'})
